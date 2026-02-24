<?php

declare(strict_types=1);

namespace Semitexa\Orm\Sync;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Schema\ColumnDefinition;
use Semitexa\Orm\Schema\DbColumnState;
use Semitexa\Orm\Schema\SchemaDiff;
use Semitexa\Orm\Schema\TableDefinition;

class SyncEngine
{
    private const DEPRECATED_COMMENT = 'SEMITEXA_DEPRECATED';

    public function __construct(
        private readonly DatabaseAdapterInterface $adapter,
        private readonly ?AuditLogger $auditLogger = null,
    ) {}

    public function buildPlan(SchemaDiff $diff): ExecutionPlan
    {
        $plan = new ExecutionPlan();

        // 1. CREATE TABLEs first (sorted by dependencies)
        $sortedTables = $this->topologicalSort($diff->getCreateTables());
        foreach ($sortedTables as $table) {
            $plan->addOperation(new DdlOperation(
                sql: $this->generateCreateTable($table),
                type: DdlOperationType::CreateTable,
                tableName: $table->name,
                isDestructive: false,
                description: "Create table '{$table->name}'",
            ));
        }

        // 2. ADD COLUMNs (safe)
        foreach ($diff->getAddColumns() as $tableName => $columns) {
            foreach ($columns as $column) {
                $plan->addOperation(new DdlOperation(
                    sql: $this->generateAddColumn($tableName, $column),
                    type: DdlOperationType::AddColumn,
                    tableName: $tableName,
                    isDestructive: false,
                    description: "Add column '{$column->name}' to '{$tableName}'",
                ));
            }
        }

        // 3. ALTER COLUMNs (may be safe or destructive)
        foreach ($diff->getAlterColumns() as $tableName => $alterations) {
            foreach ($alterations as $alteration) {
                $column = $alteration['column'];
                $changes = $alteration['changes'];
                $isDestructive = $this->isAlterDestructive($changes);

                $plan->addOperation(new DdlOperation(
                    sql: $this->generateAlterColumn($tableName, $column),
                    type: DdlOperationType::AlterColumn,
                    tableName: $tableName,
                    isDestructive: $isDestructive,
                    description: "Alter column '{$column->name}' in '{$tableName}': " . implode(', ', $changes),
                ));
            }
        }

        // 4. ADD INDEXes (safe)
        foreach ($diff->getAddIndexes() as $tableName => $indexes) {
            foreach ($indexes as $entry) {
                $index = $entry['index'];
                $name = $entry['name'];
                $plan->addOperation(new DdlOperation(
                    sql: $this->generateAddIndex($tableName, $index, $name),
                    type: DdlOperationType::AddIndex,
                    tableName: $tableName,
                    isDestructive: false,
                    description: "Add index '{$name}' on '{$tableName}'",
                ));
            }
        }

        // 5. DROP INDEXes (destructive)
        foreach ($diff->getDropIndexes() as $tableName => $indexNames) {
            foreach ($indexNames as $indexName) {
                $plan->addOperation(new DdlOperation(
                    sql: "ALTER TABLE `{$tableName}` DROP INDEX `{$indexName}`",
                    type: DdlOperationType::DropIndex,
                    tableName: $tableName,
                    isDestructive: true,
                    description: "Drop index '{$indexName}' from '{$tableName}'",
                ));
            }
        }

        // 6. DROP COLUMNs — two-phase logic (destructive)
        foreach ($diff->getDropColumns() as $tableName => $columns) {
            foreach ($columns as $colInfo) {
                $columnName = $colInfo['name'];
                $comment    = $colInfo['comment'];
                $dbState    = $colInfo['dbState'];

                if ($comment !== self::DEPRECATED_COMMENT) {
                    // Column was not previously marked as deprecated → block drop, add deprecation comment instead.
                    // MODIFY COLUMN requires the full column definition — reconstruct it from DbColumnState.
                    $plan->addOperation(new DdlOperation(
                        sql: $this->generateDeprecationDdl($tableName, $dbState),
                        type: DdlOperationType::AlterColumn,
                        tableName: $tableName,
                        isDestructive: false,
                        description: "Mark column '{$columnName}' in '{$tableName}' as deprecated (two-phase drop, phase 1)",
                    ));
                } else {
                    // Column was already deprecated → safe to drop
                    $plan->addOperation(new DdlOperation(
                        sql: "ALTER TABLE `{$tableName}` DROP COLUMN `{$columnName}`",
                        type: DdlOperationType::DropColumn,
                        tableName: $tableName,
                        isDestructive: true,
                        description: "Drop deprecated column '{$columnName}' from '{$tableName}' (two-phase drop, phase 2)",
                    ));
                }
            }
        }

        // 7. DROP TABLEs (destructive)
        foreach ($diff->getDropTables() as $tableName) {
            $plan->addOperation(new DdlOperation(
                sql: "DROP TABLE `{$tableName}`",
                type: DdlOperationType::DropTable,
                tableName: $tableName,
                isDestructive: true,
                description: "Drop table '{$tableName}'",
            ));
        }

        return $plan;
    }

    /**
     * Execute a plan against the database.
     *
     * @return DdlOperation[] Executed operations
     */
    public function execute(ExecutionPlan $plan, bool $allowDestructive = false): array
    {
        $executed = [];

        foreach ($plan->getOperations() as $operation) {
            if ($operation->isDestructive && !$allowDestructive) {
                continue;
            }

            $this->adapter->execute($operation->sql);
            $executed[] = $operation;
        }

        $this->auditLogger?->log($executed);

        return $executed;
    }

    private function generateCreateTable(TableDefinition $table): string
    {
        $lines = [];
        $pk = null;

        foreach ($table->getColumns() as $col) {
            $line = '  ' . $this->generateColumnDdl($col);
            if ($col->isPrimaryKey) {
                $pk = $col;
            }
            $lines[] = $line;
        }

        if ($pk !== null) {
            $lines[] = "  PRIMARY KEY (`{$pk->name}`)";
        }

        foreach ($table->getIndexes() as $index) {
            $name = $index->name ?? $this->generateIndexName($table->name, $index->columns, $index->unique);
            $cols = implode('`, `', $index->columns);
            $prefix = $index->unique ? 'UNIQUE KEY' : 'KEY';
            $lines[] = "  {$prefix} `{$name}` (`{$cols}`)";
        }

        $body = implode(",\n", $lines);
        return "CREATE TABLE `{$table->name}` (\n{$body}\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    private function generateColumnDdl(ColumnDefinition $col): string
    {
        $type = $this->sqlType($col);
        $null = $col->nullable ? 'NULL' : 'NOT NULL';
        $auto = ($col->isPrimaryKey && $col->pkStrategy === 'auto' && in_array($col->type, [MySqlType::Int, MySqlType::Bigint], true))
            ? ' AUTO_INCREMENT'
            : '';
        $default = $this->defaultClause($col);
        $deprecated = $col->isDeprecated ? " COMMENT '" . self::DEPRECATED_COMMENT . "'" : '';

        return "`{$col->name}` {$type} {$null}{$auto}{$default}{$deprecated}";
    }

    private function sqlType(ColumnDefinition $col): string
    {
        return match ($col->type) {
            MySqlType::Varchar => 'VARCHAR(' . ($col->length ?? 255) . ')',
            MySqlType::Text => 'TEXT',
            MySqlType::Int => 'INT',
            MySqlType::Bigint => 'BIGINT',
            MySqlType::Decimal => 'DECIMAL(' . ($col->precision ?? 10) . ',' . ($col->scale ?? 0) . ')',
            MySqlType::Boolean => 'TINYINT(1)',
            MySqlType::Datetime => 'DATETIME',
            MySqlType::Timestamp => 'TIMESTAMP',
            MySqlType::Date => 'DATE',
            MySqlType::Json => 'JSON',
        };
    }

    private function defaultClause(ColumnDefinition $col): string
    {
        if ($col->default === null && !$col->nullable) {
            return '';
        }

        if ($col->default === null) {
            return ' DEFAULT NULL';
        }

        if (is_bool($col->default)) {
            return ' DEFAULT ' . ($col->default ? '1' : '0');
        }

        if (is_int($col->default) || is_float($col->default)) {
            return ' DEFAULT ' . $col->default;
        }

        return " DEFAULT '" . addslashes((string) $col->default) . "'";
    }

    private function generateAddColumn(string $tableName, ColumnDefinition $col): string
    {
        $ddl = $this->generateColumnDdl($col);
        return "ALTER TABLE `{$tableName}` ADD COLUMN {$ddl}";
    }

    private function generateAlterColumn(string $tableName, ColumnDefinition $col): string
    {
        $ddl = $this->generateColumnDdl($col);
        return "ALTER TABLE `{$tableName}` MODIFY COLUMN {$ddl}";
    }

    /**
     * Generate MODIFY COLUMN DDL that marks a live DB column as deprecated.
     *
     * MySQL MODIFY COLUMN requires the complete column definition — omitting
     * the type causes MySQL to silently reset it to a default. We reconstruct
     * the full definition from DbColumnState (the live DB snapshot read via
     * INFORMATION_SCHEMA) and append the deprecation comment.
     */
    private function generateDeprecationDdl(string $tableName, DbColumnState $col): string
    {
        // columnType from INFORMATION_SCHEMA is the authoritative full type string
        // (e.g. "varchar(255)", "decimal(10,2)", "tinyint(1)") — use it verbatim.
        $null    = $col->nullable ? 'NULL' : 'NOT NULL';
        $auto    = $col->isAutoIncrement ? ' AUTO_INCREMENT' : '';
        $default = '';

        if ($col->defaultValue !== null) {
            $default = " DEFAULT '" . addslashes($col->defaultValue) . "'";
        } elseif ($col->nullable) {
            $default = ' DEFAULT NULL';
        }

        $comment = " COMMENT '" . self::DEPRECATED_COMMENT . "'";

        $ddl = "`{$col->name}` {$col->columnType} {$null}{$auto}{$default}{$comment}";

        return "ALTER TABLE `{$tableName}` MODIFY COLUMN {$ddl}";
    }

    /**
     * @param \Semitexa\Orm\Schema\IndexDefinition $index
     */
    private function generateAddIndex(string $tableName, $index, string $name): string
    {
        $cols = implode('`, `', $index->columns);
        $type = $index->unique ? 'UNIQUE INDEX' : 'INDEX';
        return "ALTER TABLE `{$tableName}` ADD {$type} `{$name}` (`{$cols}`)";
    }

    /**
     * Topological sort of tables by FK dependencies (BelongsTo relations).
     * Tables without dependencies come first.
     *
     * @param TableDefinition[] $tables
     * @return TableDefinition[]
     */
    private function topologicalSort(array $tables): array
    {
        $tableMap = [];
        foreach ($tables as $table) {
            $tableMap[$table->name] = $table;
        }

        // Build dependency graph
        $deps = [];
        foreach ($tables as $table) {
            $deps[$table->name] = [];
            foreach ($table->getRelations() as $relation) {
                if ($relation['type'] === 'belongs_to') {
                    // Extract target table name from resource class
                    $deps[$table->name][] = $relation['target'];
                }
            }
        }

        $sorted = [];
        $visited = [];
        $visiting = [];

        $visit = function (string $name) use (&$visit, &$sorted, &$visited, &$visiting, $tableMap, $deps): void {
            if (isset($visited[$name])) {
                return;
            }
            if (isset($visiting[$name])) {
                // Circular dependency — just add it; CREATE TABLE handles FK separately
                return;
            }

            $visiting[$name] = true;

            foreach ($deps[$name] ?? [] as $dep) {
                if (isset($tableMap[$dep])) {
                    $visit($dep);
                }
            }

            unset($visiting[$name]);
            $visited[$name] = true;

            if (isset($tableMap[$name])) {
                $sorted[] = $tableMap[$name];
            }
        };

        foreach ($tableMap as $name => $table) {
            $visit($name);
        }

        return $sorted;
    }

    /**
     * Determine if column alteration is destructive.
     *
     * @param string[] $changes
     */
    private function isAlterDestructive(array $changes): bool
    {
        foreach ($changes as $change) {
            if (str_starts_with($change, 'type:')) {
                // Type change is potentially destructive — check if it's widening
                if ($this->isTypeWidening($change)) {
                    continue;
                }
                return true;
            }
        }

        return false;
    }

    private function isTypeWidening(string $change): bool
    {
        // Extract old → new from "type: old → new"
        if (!preg_match('/type:\s*(.+?)\s*→\s*(.+)/', $change, $matches)) {
            return false;
        }

        $old = strtolower(trim($matches[1]));
        $new = strtolower(trim($matches[2]));

        // VARCHAR(N) → VARCHAR(M) where M >= N
        if (preg_match('/^varchar\((\d+)\)$/', $old, $oldM) && preg_match('/^varchar\((\d+)\)$/', $new, $newM)) {
            return (int) $newM[1] >= (int) $oldM[1];
        }

        // VARCHAR(any) → TEXT/MEDIUMTEXT/LONGTEXT — always wider
        if (str_starts_with($old, 'varchar(') && in_array($new, ['text', 'mediumtext', 'longtext'], true)) {
            return true;
        }

        // TEXT → MEDIUMTEXT → LONGTEXT
        $textOrder = ['text' => 0, 'mediumtext' => 1, 'longtext' => 2];
        if (isset($textOrder[$old], $textOrder[$new])) {
            return $textOrder[$new] >= $textOrder[$old];
        }

        // INT → BIGINT
        if ($old === 'int' && $new === 'bigint') {
            return true;
        }

        // TINYINT(1) → INT or BIGINT
        if ($old === 'tinyint(1)' && in_array($new, ['int', 'bigint'], true)) {
            return true;
        }

        return false;
    }

    /**
     * @param string[] $columns
     */
    private function generateIndexName(string $tableName, array $columns, bool $unique): string
    {
        $prefix = $unique ? 'uniq' : 'idx';
        return $prefix . '_' . $tableName . '_' . implode('_', $columns);
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Orm\Sync;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\DatabaseType;
use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Adapter\SqliteType;
use Semitexa\Orm\Schema\ColumnDefinition;
use Semitexa\Orm\Schema\DbColumnState;
use Semitexa\Orm\Schema\ForeignKeyDefinition;
use Semitexa\Orm\Schema\ResourceMetadata;
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

            if ($this->isSqlite()) {
                foreach ($table->getIndexes() as $index) {
                    $name = $index->name ?? $this->generateIndexName($table->name, $index->columns, $index->unique);
                    $plan->addOperation(new DdlOperation(
                        sql: $this->generateAddIndex($table->name, $index, $name),
                        type: DdlOperationType::AddIndex,
                        tableName: $table->name,
                        isDestructive: false,
                        description: "Add index '{$name}' on '{$table->name}'",
                    ));
                }
            }
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

        // 4. ADD FOREIGN KEYs (safe — all tables already exist at this point)
        foreach ($diff->getAddForeignKeys() as $fk) {
            $plan->addOperation(new DdlOperation(
                sql: $this->generateAddForeignKey($fk),
                type: DdlOperationType::AddForeignKey,
                tableName: $fk->table,
                isDestructive: false,
                description: "Add FK constraint '{$fk->constraintName()}' on '{$fk->table}'.{$fk->column} → '{$fk->referencedTable}'.{$fk->referencedColumn}",
            ));
        }

        // 5–6. INDEX changes: DROP + ADD
        //
        // When an index is being recreated (same name appears in both drop and add
        // lists for the same table), MySQL may refuse to drop it if a FK constraint
        // depends on it (error 1553). Combining DROP INDEX + ADD INDEX into a single
        // ALTER TABLE statement lets MySQL atomically swap the index definition
        // without ever leaving the FK unsupported.
        $dropIndexes = $diff->getDropIndexes();
        $addIndexes  = $diff->getAddIndexes();

        // Build a lookup of add-indexes keyed by table.name for pairing
        $addByTableAndName = [];
        foreach ($addIndexes as $tableName => $indexes) {
            foreach ($indexes as $entry) {
                $addByTableAndName[$tableName . '.' . $entry['name']] = $entry;
            }
        }

        // Track which add-indexes have been paired with a drop (emitted as combined DDL)
        $pairedAdds = [];

        // 5. DROP INDEXes (destructive — must run before ADD to avoid duplicate key names)
        foreach ($dropIndexes as $tableName => $indexNames) {
            foreach ($indexNames as $indexName) {
                $key = $tableName . '.' . $indexName;
                $q = $this->quoteChar();

                if (isset($addByTableAndName[$key])) {
                    // Same index name is being dropped and re-added → combine into one statement
                    $entry = $addByTableAndName[$key];
                    $index = $entry['index'];

                    if ($this->isSqlite()) {
                        // SQLite: separate DROP and CREATE
                        $plan->addOperation(new DdlOperation(
                            sql: "DROP INDEX {$q}{$indexName}{$q}",
                            type: DdlOperationType::DropIndex,
                            tableName: $tableName,
                            isDestructive: true,
                            description: "Drop index '{$indexName}' from '{$tableName}'",
                        ));
                        $plan->addOperation(new DdlOperation(
                            sql: $this->generateAddIndex($tableName, $index, $indexName),
                            type: DdlOperationType::AddIndex,
                            tableName: $tableName,
                            isDestructive: false,
                            description: "Recreate index '{$indexName}' on '{$tableName}'",
                        ));
                    } else {
                        $cols = implode('`, `', $index->columns);
                        $type = $index->unique ? 'UNIQUE INDEX' : 'INDEX';
                        $sql = "ALTER TABLE `{$tableName}` DROP INDEX `{$indexName}`, ADD {$type} `{$indexName}` (`{$cols}`)";

                        $plan->addOperation(new DdlOperation(
                            sql: $sql,
                            type: DdlOperationType::DropIndex,
                            tableName: $tableName,
                            isDestructive: true,
                            description: "Recreate index '{$indexName}' on '{$tableName}'",
                        ));
                    }
                    $pairedAdds[$key] = true;
                } else {
                    if ($this->isSqlite()) {
                        $plan->addOperation(new DdlOperation(
                            sql: "DROP INDEX {$q}{$indexName}{$q}",
                            type: DdlOperationType::DropIndex,
                            tableName: $tableName,
                            isDestructive: true,
                            description: "Drop index '{$indexName}' from '{$tableName}'",
                        ));
                    } else {
                        $plan->addOperation(new DdlOperation(
                            sql: "ALTER TABLE `{$tableName}` DROP INDEX `{$indexName}`",
                            type: DdlOperationType::DropIndex,
                            tableName: $tableName,
                            isDestructive: true,
                            description: "Drop index '{$indexName}' from '{$tableName}'",
                        ));
                    }
                }
            }
        }

        // 6. ADD INDEXes (safe) — skip any that were already emitted as part of a combined statement
        foreach ($addIndexes as $tableName => $indexes) {
            foreach ($indexes as $entry) {
                $key = $tableName . '.' . $entry['name'];
                if (isset($pairedAdds[$key])) {
                    continue;
                }

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

        // 7. DROP COLUMNs — two-phase logic (destructive)
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
                    $q = $this->quoteChar();
                    $plan->addOperation(new DdlOperation(
                        sql: "ALTER TABLE {$q}{$tableName}{$q} DROP COLUMN {$q}{$columnName}{$q}",
                        type: DdlOperationType::DropColumn,
                        tableName: $tableName,
                        isDestructive: true,
                        description: "Drop deprecated column '{$columnName}' from '{$tableName}' (two-phase drop, phase 2)",
                    ));
                }
            }
        }

        // 8. DROP FOREIGN KEYs (destructive — must happen before DROP TABLE/COLUMN)
        foreach ($diff->getDropForeignKeys() as $entry) {
            if ($this->isSqlite()) {
                // SQLite: FK constraints cannot be dropped separately.
                // Table recreation would be needed — skip for now.
                continue;
            }
            $plan->addOperation(new DdlOperation(
                sql: "ALTER TABLE `{$entry['table']}` DROP FOREIGN KEY `{$entry['constraintName']}`",
                type: DdlOperationType::DropForeignKey,
                tableName: $entry['table'],
                isDestructive: true,
                description: "Drop FK constraint '{$entry['constraintName']}' from '{$entry['table']}'",
            ));
        }

        // 9. DROP TABLEs — two-phase logic (destructive)
        foreach ($diff->getDropTables() as $dbTable) {
            $tableName = $dbTable->name;
            $q = $this->quoteChar();
            if ($dbTable->tableComment !== self::DEPRECATED_COMMENT) {
                if ($this->isSqlite()) {
                    // SQLite lacks table comments, so do not drop the table implicitly.
                    // Force an explicit/manual follow-up instead of risking silent data loss.
                    $plan->addOperation(new DdlOperation(
                        sql: "-- SQLITE_DROP_TABLE_REQUIRES_MANUAL_REVIEW:{$tableName}",
                        type: DdlOperationType::AlterColumn,
                        tableName: $tableName,
                        isDestructive: false,
                        description: "Manual review required before dropping SQLite table '{$tableName}'",
                    ));
                } else {
                    // Table was not previously marked as deprecated → block drop, add deprecation comment instead.
                    $plan->addOperation(new DdlOperation(
                        sql: "ALTER TABLE `{$tableName}` COMMENT '" . self::DEPRECATED_COMMENT . "'",
                        type: DdlOperationType::AlterColumn,
                        tableName: $tableName,
                        isDestructive: false,
                        description: "Mark table '{$tableName}' as deprecated (two-phase drop, phase 1)",
                    ));
                }
            } else {
                // Table was already deprecated → safe to drop
                $plan->addOperation(new DdlOperation(
                    sql: "DROP TABLE `{$tableName}`",
                    type: DdlOperationType::DropTable,
                    tableName: $tableName,
                    isDestructive: true,
                    description: "Drop deprecated table '{$tableName}' (two-phase drop, phase 2)",
                ));
            }
        }

        return $plan;
    }

    /**
     * Execute a plan against the database.
     *
     * When the server supports atomic DDL (MySQL 8.0+), all operations are
     * wrapped in a single transaction so a mid-plan failure rolls back cleanly.
     * On older MySQL/MariaDB, operations are applied one by one (no rollback on failure).
     *
     * @return DdlOperation[] Executed operations
     */
    public function execute(ExecutionPlan $plan, bool $allowDestructive = false): array
    {
        $operations = array_filter(
            $plan->getOperations(),
            fn(DdlOperation $op) => $allowDestructive || !$op->isDestructive,
        );

        if ($operations === []) {
            return [];
        }

        $useTransaction = $this->adapter->supports(\Semitexa\Orm\Adapter\ServerCapability::AtomicDdl);
        $isSqlite = $this->isSqlite();

        $executed = [];
        try {
            if ($useTransaction) {
                $this->adapter->query($isSqlite ? 'BEGIN' : 'START TRANSACTION');
            }

            foreach ($operations as $operation) {
                if ($isSqlite && $this->isSqlitePlaceholder($operation->sql)) {
                    throw new \RuntimeException(
                        "SQLite sync requires table recreation for unsupported operation: {$operation->description}",
                    );
                }

                $this->adapter->execute($operation->sql);
                $executed[] = $operation;
            }

            if ($useTransaction) {
                $this->adapter->query($isSqlite ? 'COMMIT' : 'COMMIT');
            }
        } catch (\Throwable $e) {
            if ($useTransaction) {
                $this->adapter->query($isSqlite ? 'ROLLBACK' : 'ROLLBACK');
            }
            throw $e;
        }

        $this->auditLogger?->log($executed);

        return $executed;
    }

    /**
     * Check if SQL is a SQLite placeholder for unsupported operations.
     */
    private function isSqlitePlaceholder(string $sql): bool
    {
        return str_starts_with($sql, '-- SQLITE_');
    }

    private function generateCreateTable(TableDefinition $table): string
    {
        $isSqlite = $this->isSqlite();
        $lines = [];
        $pk = null;
        $inlineFks = [];

        foreach ($table->getColumns() as $col) {
            $line = '  ' . $this->generateColumnDdl($col);
            if ($col->isPrimaryKey) {
                $pk = $col;
            }
            $lines[] = $line;
        }

        if ($pk !== null) {
            if ($isSqlite && $pk->pkStrategy === 'auto' && $this->isSqliteAutoIncrementPrimaryKey($pk)) {
                // SQLite: INTEGER PRIMARY KEY implies AUTOINCREMENT behavior
                // Already handled in generateColumnDdl
            } else {
                $q = $isSqlite ? '"' : '`';
                $lines[] = "  PRIMARY KEY ({$q}{$pk->name}{$q})";
            }
        }

        foreach ($table->getIndexes() as $index) {
            $name = $index->name ?? $this->generateIndexName($table->name, $index->columns, $index->unique);
            $q = $isSqlite ? '"' : '`';
            $cols = implode("{$q}, {$q}", $index->columns);
            if ($isSqlite) {
                // SQLite: indexes are created separately, not inline in CREATE TABLE
                // We'll handle them after table creation
            } else {
                $prefix = $index->unique ? 'UNIQUE KEY' : 'KEY';
                $lines[] = "  {$prefix} `{$name}` (`{$cols}`)";
            }
        }

        // Add inline FK constraints for SQLite (must be in CREATE TABLE)
        if ($isSqlite) {
            foreach ($table->getForeignKeys() as $fk) {
                $lines[] = "  FOREIGN KEY (\"{$fk->column}\") REFERENCES \"{$fk->referencedTable}\"(\"{$fk->referencedColumn}\") ON DELETE {$fk->onDelete->value} ON UPDATE {$fk->onUpdate->value}";
            }
        }

        $body = implode(",\n", $lines);
        $q = $isSqlite ? '"' : '`';

        if ($isSqlite) {
            return "CREATE TABLE {$q}{$table->name}{$q} (\n{$body}\n)";
        }

        return "CREATE TABLE `{$table->name}` (\n{$body}\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    private function generateColumnDdl(ColumnDefinition $col): string
    {
        $isSqlite = $this->isSqlite();
        $q = $this->quoteChar();
        $type = $this->sqlType($col);
        $null = $col->nullable ? 'NULL' : 'NOT NULL';

        // Auto-increment handling
        $auto = '';
        if ($col->isPrimaryKey && $col->pkStrategy === 'auto') {
            if ($isSqlite && $this->isSqliteAutoIncrementPrimaryKey($col)) {
                // SQLite: INTEGER PRIMARY KEY auto-increments implicitly
                // Type is already INTEGER from sqlType()
                $auto = ' PRIMARY KEY AUTOINCREMENT';
                // In SQLite, we skip the separate PRIMARY KEY clause
                // and the NULL clause for autoincrement PKs
                $deprecated = $col->isDeprecated ? " -- " . self::DEPRECATED_COMMENT : '';
                return "{$q}{$col->name}{$q} {$type}{$auto}{$deprecated}";
            } elseif ($col->type instanceof MySqlType && in_array($col->type, [MySqlType::Int, MySqlType::Bigint], true)) {
                $auto = ' AUTO_INCREMENT';
            }
        }

        $default = $this->defaultClause($col);
        $deprecated = !$isSqlite && $col->isDeprecated ? " COMMENT '" . self::DEPRECATED_COMMENT . "'" : '';

        return "{$q}{$col->name}{$q} {$type} {$null}{$auto}{$default}{$deprecated}";
    }

    private function sqlType(ColumnDefinition $col): string
    {
        // Delegate to the type's own toSql() method
        return $col->type->toSql($col->length, $col->precision, $col->scale);
    }

    private function isSqliteAutoIncrementPrimaryKey(ColumnDefinition $col): bool
    {
        if ($col->type instanceof SqliteType) {
            return in_array($col->type, [SqliteType::Int, SqliteType::Bigint], true);
        }

        if ($col->type instanceof MySqlType) {
            return in_array($col->type, [MySqlType::Int, MySqlType::Bigint], true);
        }

        return false;
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

        return " DEFAULT '" . str_replace("'", "''", (string) $col->default) . "'";
    }

    private function generateAddColumn(string $tableName, ColumnDefinition $col): string
    {
        $q = $this->quoteChar();
        $ddl = $this->generateColumnDdl($col);
        return "ALTER TABLE {$q}{$tableName}{$q} ADD COLUMN {$ddl}";
    }

    private function generateAlterColumn(string $tableName, ColumnDefinition $col): string
    {
        if ($this->isSqlite()) {
            // SQLite has very limited ALTER TABLE support.
            // For column alterations, we need to recreate the table.
            // This is handled specially in the execution phase.
            return "-- SQLITE_ALTER_COLUMN:{$tableName}:{$col->name}";
        }

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
        if ($this->isSqlite()) {
            // SQLite doesn't support column comments.
            // We skip deprecation marking for SQLite.
            return "-- SQLITE_DEPRECATION_NOT_SUPPORTED";
        }

        // columnType from INFORMATION_SCHEMA is the authoritative full type string
        // (e.g. "varchar(255)", "decimal(10,2)", "tinyint(1)") — use it verbatim.
        $null    = $col->nullable ? 'NULL' : 'NOT NULL';
        $auto    = $col->isAutoIncrement ? ' AUTO_INCREMENT' : '';
        $default = '';

        if ($col->defaultValue !== null) {
            $default = " DEFAULT '" . str_replace("'", "''", $col->defaultValue) . "'";
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
        $q = $this->quoteChar();
        $cols = implode("{$q}, {$q}", $index->columns);

        if ($this->isSqlite()) {
            $type = $index->unique ? 'UNIQUE INDEX' : 'INDEX';
            return "CREATE {$type} {$q}{$name}{$q} ON {$q}{$tableName}{$q} ({$q}{$cols}{$q})";
        }

        $type = $index->unique ? 'UNIQUE INDEX' : 'INDEX';
        return "ALTER TABLE `{$tableName}` ADD {$type} `{$name}` (`{$cols}`)";
    }

    private function generateAddForeignKey(ForeignKeyDefinition $fk): string
    {
        $q = $this->quoteChar();

        if ($this->isSqlite()) {
            // SQLite: FK constraints must be added during table creation.
            // For existing tables, we need to recreate the table.
            return "-- SQLITE_ADD_FK:{$fk->table}:{$fk->column}:{$fk->referencedTable}:{$fk->referencedColumn}";
        }

        $name = $fk->constraintName();
        return sprintf(
            'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s`(`%s`) ON DELETE %s ON UPDATE %s',
            $fk->table,
            $name,
            $fk->column,
            $fk->referencedTable,
            $fk->referencedColumn,
            $fk->onDelete->value,
            $fk->onUpdate->value,
        );
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

        // Build a reverse map: FQCN resource class → table name.
        // Relations store target as a FQCN (e.g. App\Resource\UserResource), but
        // $tableMap is keyed by table name (e.g. 'users'). Without this mapping
        // the dependency lookup always misses, leaving tables in arbitrary order.
        $classToTable = [];
        foreach ($tables as $table) {
            foreach ($table->getRelations() as $relation) {
                $targetClass = $relation['target'];
                if (isset($classToTable[$targetClass])) {
                    continue;
                }
                try {
                    $meta = \Semitexa\Orm\Schema\ResourceMetadata::for($targetClass);
                    $classToTable[$targetClass] = $meta->getTableName();
                } catch (\Throwable) {
                    // Target class not available in this context — skip gracefully
                }
            }
        }

        // Build dependency graph using table names throughout
        $deps = [];
        foreach ($tables as $table) {
            $deps[$table->name] = [];
            foreach ($table->getRelations() as $relation) {
                if ($relation['type'] === 'belongs_to') {
                    $targetTable = $classToTable[$relation['target']] ?? null;
                    if ($targetTable !== null && isset($tableMap[$targetTable])) {
                        $deps[$table->name][] = $targetTable;
                    }
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

        // Integer widening order: TINYINT → SMALLINT → INT → BIGINT
        $intOrder = ['tinyint' => 0, 'smallint' => 1, 'int' => 2, 'bigint' => 3];
        $oldBase = preg_replace('/\(\d+\)/', '', $old); // strip (1) from tinyint(1)
        if (isset($intOrder[$oldBase], $intOrder[$new])) {
            return $intOrder[$new] >= $intOrder[$oldBase];
        }

        // Float widening: FLOAT → DOUBLE
        if ($old === 'float' && $new === 'double') {
            return true;
        }

        // CHAR(N) → CHAR(M) where M >= N
        if (preg_match('/^char\((\d+)\)$/', $old, $oldM) && preg_match('/^char\((\d+)\)$/', $new, $newM)) {
            return (int) $newM[1] >= (int) $oldM[1];
        }

        // CHAR(any) → VARCHAR(any) — always wider (fixed → variable)
        if (str_starts_with($old, 'char(') && str_starts_with($new, 'varchar(')) {
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

    /**
     * Check if the current adapter is SQLite.
     */
    private function isSqlite(): bool
    {
        return $this->adapter instanceof \Semitexa\Orm\Adapter\SqliteAdapter;
    }

    /**
     * Get the quote character for the current database.
     */
    private function quoteChar(): string
    {
        return $this->isSqlite() ? '"' : '`';
    }
}

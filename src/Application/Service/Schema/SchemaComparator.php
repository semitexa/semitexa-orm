<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service\Schema;

use Semitexa\Orm\Application\Service\Sync\SyncEngine;

use Semitexa\Orm\Domain\Model\ColumnDefinition;
use Semitexa\Orm\Domain\Model\DbColumnState;
use Semitexa\Orm\Domain\Model\DbIndexState;
use Semitexa\Orm\Domain\Model\DbTableState;
use Semitexa\Orm\Domain\Model\SchemaDiff;
use Semitexa\Orm\Domain\Model\TableDefinition;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\MySqlType;

class SchemaComparator extends AbstractSchemaComparator
{
    /** @param string[] $ignoreTables Table names to exclude from DROP detection */
    public function __construct(
        private readonly DatabaseAdapterInterface $adapter,
        private readonly string $database,
        private readonly array $ignoreTables = [],
    ) {}

    /**
     * Compare code schema with actual DB state.
     *
     * @param array<string, TableDefinition> $codeSchema
     * @return SchemaDiff
     */
    public function compare(array $codeSchema): SchemaDiff
    {
        $dbSchema = $this->readDatabaseSchema();
        $diff = new SchemaDiff();

        // Read which indexes are used by FK constraints — these must not be dropped
        // (MySQL error 1553). MySQL may use ANY suitable index for a FK, not just one
        // named after the constraint.
        $this->fkIndexNames = $this->readFkIndexNames();

        $dbFks = $this->readDbForeignKeys();

        // Tables in code but not in DB → CREATE
        foreach ($codeSchema as $tableName => $tableDefinition) {
            if (!isset($dbSchema[$tableName])) {
                $diff->addCreateTable($tableDefinition);
                continue;
            }

            // A table back in code keeps its deprecation mark unless it is
            // cleared: phase 1 would then already be done, and the next sync
            // that missed the table (a resource not discovered for once)
            // dropped it straight away.
            if ($dbSchema[$tableName]->tableComment === SyncEngine::DEPRECATED_COMMENT) {
                $diff->addRestoreTable($tableName);
            }

            // Table exists — compare columns and indexes
            $this->compareTable($tableDefinition, $dbSchema[$tableName], $diff);
        }

        // Tables in DB but not in code → DROP (skip ignored tables)
        foreach ($dbSchema as $tableName => $dbTable) {
            if (!isset($codeSchema[$tableName]) && !in_array($tableName, $this->ignoreTables, true)) {
                $diff->addDropTable($dbTable);
            }
        }

        // Compare foreign keys
        $this->compareForeignKeys($codeSchema, $diff, $dbFks);

        return $diff;
    }

    /**
     * Read actual DB schema from INFORMATION_SCHEMA.
     *
     * @return array<string, DbTableState>
     */
    private function readDatabaseSchema(): array
    {
        $tables = [];

        // Read table comments (needed for two-phase DROP TABLE detection)
        $tableComments = [];
        $tableResult = $this->adapter->execute(
            'SELECT TABLE_NAME, TABLE_COMMENT
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = :db AND TABLE_TYPE = \'BASE TABLE\'',
            ['db' => $this->database],
        );
        foreach ($tableResult->rows as $row) {
            $tableComments[self::text($row, 'TABLE_NAME')] = self::textOrNull($row, 'TABLE_COMMENT') ?? '';
        }

        // Read columns
        $result = $this->adapter->execute(
            'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT,
                    COLUMN_KEY, EXTRA, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH,
                    NUMERIC_PRECISION, NUMERIC_SCALE, COLUMN_COMMENT
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = :db
             ORDER BY TABLE_NAME, ORDINAL_POSITION',
            ['db' => $this->database],
        );

        foreach ($result->rows as $row) {
            $tableName = self::text($row, 'TABLE_NAME');
            if (in_array($tableName, $this->ignoreTables, true)) {
                continue;
            }
            if (!isset($tables[$tableName])) {
                $tables[$tableName] = new DbTableState($tableName, $tableComments[$tableName] ?? '');
            }
            $tables[$tableName]->addColumn(new DbColumnState(
                name: self::text($row, 'COLUMN_NAME'),
                dataType: self::text($row, 'DATA_TYPE'),
                columnType: self::text($row, 'COLUMN_TYPE'),
                nullable: self::textOrNull($row, 'IS_NULLABLE') === 'YES',
                defaultValue: self::textOrNull($row, 'COLUMN_DEFAULT'),
                isPrimaryKey: self::textOrNull($row, 'COLUMN_KEY') === 'PRI',
                isAutoIncrement: str_contains(self::textOrNull($row, 'EXTRA') ?? '', 'auto_increment'),
                maxLength: self::number($row, 'CHARACTER_MAXIMUM_LENGTH'),
                numericPrecision: self::number($row, 'NUMERIC_PRECISION'),
                numericScale: self::number($row, 'NUMERIC_SCALE'),
                comment: self::textOrNull($row, 'COLUMN_COMMENT') ?? '',
            ));
        }

        // Read indexes
        $indexResult = $this->adapter->execute(
            'SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SEQ_IN_INDEX
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = :db
             ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
            ['db' => $this->database],
        );

        /** @var array<string, array{table: string, name: string, columns: list<string>, unique: bool}> $indexGroups */
        $indexGroups = [];
        foreach ($indexResult->rows as $row) {
            $table = self::text($row, 'TABLE_NAME');
            $name = self::text($row, 'INDEX_NAME');
            $key = $table . '.' . $name;
            if (!isset($indexGroups[$key])) {
                $indexGroups[$key] = [
                    'table' => $table,
                    'name' => $name,
                    'columns' => [],
                    'unique' => self::number($row, 'NON_UNIQUE') === 0,
                ];
            }
            $indexGroups[$key]['columns'][] = self::text($row, 'COLUMN_NAME');
        }

        foreach ($indexGroups as $idx) {
            $tableName = $idx['table'];
            if (!isset($tables[$tableName])) {
                continue;
            }
            // Skip PRIMARY index — handled via column PK flag
            if ($idx['name'] === 'PRIMARY') {
                continue;
            }
            $tables[$tableName]->addIndex(new DbIndexState(
                name: $idx['name'],
                columns: $idx['columns'],
                unique: $idx['unique'],
            ));
        }

        return $tables;
    }


    /**
     * @return string[] List of change descriptions
     */
    protected function compareColumn(ColumnDefinition $code, DbColumnState $db): array
    {
        $changes = [];

        // Compare SQL type
        $expectedType = $this->buildExpectedColumnType($code);
        if ($this->normalizeType($expectedType) !== $this->normalizeType($db->columnType)) {
            $changes[] = "type: {$db->columnType} → {$expectedType}";
        }

        // Compare nullable
        if ($code->nullable !== $db->nullable) {
            $nullStr = $code->nullable ? 'NULL' : 'NOT NULL';
            $changes[] = "nullable: " . ($db->nullable ? 'NULL' : 'NOT NULL') . " → {$nullStr}";
        }

        // Compare AUTO_INCREMENT
        $codeAutoIncrement = $code->isPrimaryKey
            && $code->pkStrategy === 'auto'
            && in_array($code->type, [MySqlType::Int, MySqlType::Bigint], true);
        if ($codeAutoIncrement !== $db->isAutoIncrement) {
            $changes[] = 'auto_increment: ' . ($db->isAutoIncrement ? 'yes' : 'no') . ' → ' . ($codeAutoIncrement ? 'yes' : 'no');
        }

        // Compare default value.
        // Normalize code default to the string form MySQL stores in INFORMATION_SCHEMA
        // (no quotes, booleans as '1'/'0').
        $codeDefault = $this->normalizeDefault($code->default);
        if ($codeDefault !== $db->defaultValue) {
            $fromStr = $db->defaultValue === null ? 'none' : "'{$db->defaultValue}'";
            $toStr   = $codeDefault      === null ? 'none' : "'{$codeDefault}'";
            $changes[] = "default: {$fromStr} → {$toStr}";
        }

        // A column back in code loses the mark phase 1 wrote on it (the MODIFY
        // writes no comment); kept, the next sync that missed it dropped it.
        if ($db->comment === SyncEngine::DEPRECATED_COMMENT && !$code->isDeprecated) {
            $changes[] = 'comment: deprecation mark cleared (back in code)';
        }

        return $changes;
    }

    /**
     * A numeric catalog cell (a length, a precision): null when the column has none.
     *
     * @param array<string, mixed> $row
     */
    private static function number(array $row, string $column): ?int
    {
        $value = self::textOrNull($row, $column);

        return $value === null ? null : (int) $value;
    }

    private function buildExpectedColumnType(ColumnDefinition $col): string
    {
        return $col->type->canonicalName($col->length, $col->precision, $col->scale);
    }

    /**
     * Convert a code-side default value to the string form MySQL stores in
     * INFORMATION_SCHEMA.COLUMNS (COLUMN_DEFAULT): no surrounding quotes,
     * booleans as '1'/'0', null means "no default".
     */
    private function normalizeDefault(mixed $default): ?string
    {
        if ($default === null) {
            return null;
        }
        if (is_bool($default)) {
            return $default ? '1' : '0';
        }
        if (is_scalar($default) || $default instanceof \Stringable) {
            return (string) $default;
        }

        // SyncEngine::defaultClause() could not write such a default either.
        throw new \UnexpectedValueException(sprintf('A column default of %s has no SQL form.', get_debug_type($default)));
    }

    private function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        // MySQL may report integer types with display width — strip it for comparison
        // tinyint keeps its width: tinyint(1) is the boolean.
        return preg_replace(['/^int\(\d+\)$/', '/^bigint\(\d+\)$/', '/^smallint\(\d+\)$/'], ['int', 'bigint', 'smallint'], $type) ?? $type;
    }



    /**
     * Compare FK constraints: code schema vs DB.
     * - FK in code but not in DB → ADD CONSTRAINT
     * - FK in DB but not in code → DROP CONSTRAINT
     * - FK exists in both but definition differs → DROP + ADD
     *
     * @param array<string, TableDefinition> $codeSchema
     * @param array<string, array{table: string, column: string, referencedTable: string, referencedColumn: string, onDelete: string, onUpdate: string}> $dbFks
     */
    private function compareForeignKeys(array $codeSchema, SchemaDiff $diff, array $dbFks): void
    {

        // Collect all code FKs keyed by constraint name
        $codeFks = [];
        foreach ($codeSchema as $table) {
            foreach ($table->getForeignKeys() as $fk) {
                $codeFks[$fk->constraintName()] = $fk;
            }
        }

        // FK in code but missing or different in DB → ADD
        foreach ($codeFks as $name => $fk) {
            if (!isset($dbFks[$name])) {
                $diff->addForeignKey($fk);
                continue;
            }

            // Check if definition changed
            $db = $dbFks[$name];
            if (
                $db['referencedTable'] !== $fk->referencedTable
                || $db['referencedColumn'] !== $fk->referencedColumn
                || strtoupper($db['onDelete']) !== $fk->onDelete->value
                || strtoupper($db['onUpdate']) !== $fk->onUpdate->value
            ) {
                $diff->addDropForeignKey($fk->table, $name);
                $diff->addForeignKey($fk);
            }
        }

        // FK in DB but not in code → DROP (only for managed tables, skip ignored)
        foreach ($dbFks as $name => $db) {
            if (!isset($codeFks[$name]) && !in_array($db['table'], $this->ignoreTables, true)) {
                $diff->addDropForeignKey($db['table'], $name);
            }
        }
    }

    /**
     * Read existing FK constraints from INFORMATION_SCHEMA.
     *
     * @return array<string, array{table: string, column: string, referencedTable: string, referencedColumn: string, onDelete: string, onUpdate: string}>
     */
    private function readDbForeignKeys(): array
    {
        $result = $this->adapter->execute(
            'SELECT
                kcu.CONSTRAINT_NAME,
                kcu.TABLE_NAME,
                kcu.COLUMN_NAME,
                kcu.REFERENCED_TABLE_NAME,
                kcu.REFERENCED_COLUMN_NAME,
                rc.DELETE_RULE,
                rc.UPDATE_RULE
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
             JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
               ON rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
              AND rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
             WHERE kcu.TABLE_SCHEMA = :db
               AND kcu.REFERENCED_TABLE_NAME IS NOT NULL',
            ['db' => $this->database],
        );

        $fks = [];
        foreach ($result->rows as $row) {
            $fks[self::text($row, 'CONSTRAINT_NAME')] = [
                'table'            => self::text($row, 'TABLE_NAME'),
                'column'           => self::text($row, 'COLUMN_NAME'),
                'referencedTable'  => self::text($row, 'REFERENCED_TABLE_NAME'),
                'referencedColumn' => self::text($row, 'REFERENCED_COLUMN_NAME'),
                'onDelete'         => self::text($row, 'DELETE_RULE'),
                'onUpdate'         => self::text($row, 'UPDATE_RULE'),
            ];
        }

        return $fks;
    }

    /**
     * Read which index names are used by FK constraints in the current database.
     * MySQL can use ANY suitable index (not just one named after the constraint).
     * These indexes must never be dropped (MySQL error 1553).
     *
     * @return array<string, true> key: "table_name.index_name"
     */
    private function readFkIndexNames(): array
    {
        // INNODB_SYS_* tables are not available in all MySQL versions.
        // Instead, for each FK we know the table+column — find which index MySQL
        // uses by checking INFORMATION_SCHEMA.STATISTICS for indexes whose first
        // column matches the FK column.
        $result = $this->adapter->execute(
            'SELECT s.TABLE_NAME, s.INDEX_NAME
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
             JOIN INFORMATION_SCHEMA.STATISTICS s
               ON s.TABLE_SCHEMA = kcu.TABLE_SCHEMA
              AND s.TABLE_NAME = kcu.TABLE_NAME
              AND s.COLUMN_NAME = kcu.COLUMN_NAME
              AND s.SEQ_IN_INDEX = 1
             WHERE kcu.TABLE_SCHEMA = :db
               AND kcu.REFERENCED_TABLE_NAME IS NOT NULL',
            ['db' => $this->database],
        );

        $names = [];
        foreach ($result->rows as $row) {
            $names[self::text($row, 'TABLE_NAME') . '.' . self::text($row, 'INDEX_NAME')] = true;
        }
        return $names;
    }
}

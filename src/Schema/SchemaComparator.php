<?php

declare(strict_types=1);

namespace Semitexa\Orm\Schema;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\MySqlType;

class SchemaComparator
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

        // Tables in code but not in DB → CREATE
        foreach ($codeSchema as $tableName => $tableDefinition) {
            if (!isset($dbSchema[$tableName])) {
                $diff->addCreateTable($tableDefinition);
                continue;
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
        $this->compareForeignKeys($codeSchema, $diff);

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
            $tableComments[$row['TABLE_NAME']] = $row['TABLE_COMMENT'];
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
            $tableName = $row['TABLE_NAME'];
            if (in_array($tableName, $this->ignoreTables, true)) {
                continue;
            }
            if (!isset($tables[$tableName])) {
                $tables[$tableName] = new DbTableState($tableName, $tableComments[$tableName] ?? '');
            }
            $tables[$tableName]->addColumn(new DbColumnState(
                name: $row['COLUMN_NAME'],
                dataType: $row['DATA_TYPE'],
                columnType: $row['COLUMN_TYPE'],
                nullable: $row['IS_NULLABLE'] === 'YES',
                defaultValue: $row['COLUMN_DEFAULT'],
                isPrimaryKey: $row['COLUMN_KEY'] === 'PRI',
                isAutoIncrement: str_contains($row['EXTRA'], 'auto_increment'),
                maxLength: $row['CHARACTER_MAXIMUM_LENGTH'] !== null ? (int) $row['CHARACTER_MAXIMUM_LENGTH'] : null,
                numericPrecision: $row['NUMERIC_PRECISION'] !== null ? (int) $row['NUMERIC_PRECISION'] : null,
                numericScale: $row['NUMERIC_SCALE'] !== null ? (int) $row['NUMERIC_SCALE'] : null,
                comment: $row['COLUMN_COMMENT'],
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

        $indexGroups = [];
        foreach ($indexResult->rows as $row) {
            $key = $row['TABLE_NAME'] . '.' . $row['INDEX_NAME'];
            if (!isset($indexGroups[$key])) {
                $indexGroups[$key] = [
                    'table' => $row['TABLE_NAME'],
                    'name' => $row['INDEX_NAME'],
                    'columns' => [],
                    'unique' => $row['NON_UNIQUE'] === '0',
                ];
            }
            $indexGroups[$key]['columns'][] = $row['COLUMN_NAME'];
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

    private function compareTable(TableDefinition $code, DbTableState $db, SchemaDiff $diff): void
    {
        $tableName = $code->name;
        $dbColumns = $db->getColumnMap();
        $codeColumns = $code->getColumns();

        // Columns in code but not in DB → ADD COLUMN
        foreach ($codeColumns as $colName => $colDef) {
            if (!isset($dbColumns[$colName])) {
                $diff->addAddColumn($tableName, $colDef);
                continue;
            }

            // Column exists — compare definition
            $dbCol = $dbColumns[$colName];
            $changes = $this->compareColumn($colDef, $dbCol);
            if ($changes !== []) {
                $diff->addAlterColumn($tableName, $colDef, $changes);
            }
        }

        // Columns in DB but not in code → DROP COLUMN
        foreach ($dbColumns as $colName => $dbCol) {
            if (!isset($codeColumns[$colName])) {
                $diff->addDropColumn($tableName, $colName, $dbCol->comment, $dbCol);
            }
        }

        // Compare indexes
        $this->compareIndexes($tableName, $code->getIndexes(), $db->getIndexes(), $diff);
    }

    /**
     * @return string[] List of change descriptions
     */
    private function compareColumn(ColumnDefinition $code, DbColumnState $db): array
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

        return $changes;
    }

    private function buildExpectedColumnType(ColumnDefinition $col): string
    {
        return match ($col->type) {
            MySqlType::Varchar => 'varchar(' . ($col->length ?? 255) . ')',
            MySqlType::Text => 'text',
            MySqlType::Int => 'int',
            MySqlType::Bigint => 'bigint',
            MySqlType::Decimal => 'decimal(' . ($col->precision ?? 10) . ',' . ($col->scale ?? 0) . ')',
            MySqlType::Boolean => 'tinyint(1)',
            MySqlType::Datetime => 'datetime',
            MySqlType::Timestamp => 'timestamp',
            MySqlType::Date => 'date',
            MySqlType::Json => 'json',
        };
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
        return (string) $default;
    }

    private function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        // MySQL 8.0 may report "int" as "int" (without display width), normalize
        $type = preg_replace('/^int\(\d+\)$/', 'int', $type);
        $type = preg_replace('/^bigint\(\d+\)$/', 'bigint', $type);
        return $type;
    }

    /**
     * @param IndexDefinition[] $codeIndexes
     * @param DbIndexState[] $dbIndexes
     */
    private function compareIndexes(string $tableName, array $codeIndexes, array $dbIndexes, SchemaDiff $diff): void
    {
        $dbIndexMap = [];
        foreach ($dbIndexes as $idx) {
            $dbIndexMap[$idx->name] = $idx;
        }

        $codeIndexMap = [];
        foreach ($codeIndexes as $idx) {
            $name = $idx->name ?? $this->generateIndexName($tableName, $idx->columns, $idx->unique);
            $codeIndexMap[$name] = $idx;
        }

        // Indexes in code but not in DB → ADD INDEX
        foreach ($codeIndexMap as $name => $idx) {
            if (!isset($dbIndexMap[$name])) {
                $diff->addAddIndex($tableName, $idx, $name);
                continue;
            }

            // Compare columns and uniqueness
            $dbIdx = $dbIndexMap[$name];
            if ($idx->columns !== $dbIdx->columns || $idx->unique !== $dbIdx->unique) {
                $diff->addDropIndex($tableName, $name);
                $diff->addAddIndex($tableName, $idx, $name);
            }
        }

        // Indexes in DB but not in code → DROP INDEX
        foreach ($dbIndexMap as $name => $dbIdx) {
            if (!isset($codeIndexMap[$name])) {
                $diff->addDropIndex($tableName, $name);
            }
        }
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
     * Compare FK constraints: code schema vs DB.
     * - FK in code but not in DB → ADD CONSTRAINT
     * - FK in DB but not in code → DROP CONSTRAINT
     * - FK exists in both but definition differs → DROP + ADD
     *
     * @param array<string, TableDefinition> $codeSchema
     */
    private function compareForeignKeys(array $codeSchema, SchemaDiff $diff): void
    {
        $dbFks = $this->readDbForeignKeys();

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
            $fks[$row['CONSTRAINT_NAME']] = [
                'table'            => $row['TABLE_NAME'],
                'column'           => $row['COLUMN_NAME'],
                'referencedTable'  => $row['REFERENCED_TABLE_NAME'],
                'referencedColumn' => $row['REFERENCED_COLUMN_NAME'],
                'onDelete'         => $row['DELETE_RULE'],
                'onUpdate'         => $row['UPDATE_RULE'],
            ];
        }

        return $fks;
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Orm\Schema;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\MySqlType;

class SchemaComparator
{
    public function __construct(
        private readonly DatabaseAdapterInterface $adapter,
        private readonly string $database,
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

        // Tables in DB but not in code → DROP
        foreach ($dbSchema as $tableName => $dbTable) {
            if (!isset($codeSchema[$tableName])) {
                $diff->addDropTable($tableName);
            }
        }

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
            if (!isset($tables[$tableName])) {
                $tables[$tableName] = new DbTableState($tableName);
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
}

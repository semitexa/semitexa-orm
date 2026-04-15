<?php

declare(strict_types=1);

namespace Semitexa\Orm\Schema;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\SqliteType;

/**
 * Schema comparator for SQLite databases.
 *
 * Uses SQLite PRAGMA statements to read database state instead of
 * INFORMATION_SCHEMA (which is MySQL-specific).
 *
 * Key differences from MySQL SchemaComparator:
 * - Uses PRAGMA table_info(), PRAGMA index_list(), PRAGMA foreign_key_list()
 * - No database/schema concept (single file)
 * - Different type comparison (SQLite uses dynamic typing)
 * - No column comments support
 * - No table comments support
 */
class SqliteSchemaComparator implements SchemaComparatorInterface
{
    /** @param string[] $ignoreTables Table names to exclude from DROP detection */
    public function __construct(
        private readonly DatabaseAdapterInterface $adapter,
        private readonly array $ignoreTables = [],
    ) {}

    /**
     * @var array<string, true> key: "table.index_name"
     */
    private array $fkIndexNames = [];

    /** @var array<string, true> */
    private array $newTableNames = [];

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
        $this->newTableNames = [];

        $this->fkIndexNames = $this->readFkIndexNames();
        $dbFks = $this->readDbForeignKeys();

        foreach ($codeSchema as $tableName => $tableDefinition) {
            if (!isset($dbSchema[$tableName])) {
                $this->newTableNames[$tableName] = true;
                $diff->addCreateTable($tableDefinition);
                continue;
            }

            $this->compareTable($tableDefinition, $dbSchema[$tableName], $diff);
        }

        foreach ($dbSchema as $tableName => $dbTable) {
            if (!isset($codeSchema[$tableName]) && !in_array($tableName, $this->ignoreTables, true)) {
                $diff->addDropTable($dbTable);
            }
        }

        $this->compareForeignKeys($codeSchema, $diff, $dbFks);

        return $diff;
    }

    /**
     * Read actual DB schema using SQLite PRAGMA statements.
     *
     * @return array<string, DbTableState>
     */
    private function readDatabaseSchema(): array
    {
        $tables = [];

        // Get all tables from sqlite_master
        $result = $this->adapter->execute(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
        );

        foreach ($result->rows as $row) {
            $tableName = $this->stringValue($row['name'] ?? null);
            if ($tableName === '') {
                continue;
            }
            if (in_array($tableName, $this->ignoreTables, true)) {
                continue;
            }

            $tables[$tableName] = new DbTableState($tableName, '');

            // Read columns via PRAGMA table_info
            $columns = $this->adapter->execute($this->buildPragmaStatement('table_info', $tableName));
            foreach ($columns->rows as $col) {
                $columnName = $this->stringValue($col['name'] ?? null);
                if ($columnName === '') {
                    continue;
                }

                $columnType = strtolower($this->stringValue($col['type'] ?? null));
                if ($columnType === '') {
                    $columnType = 'text';
                }

                $pkOrdinal = $this->intValue($col['pk'] ?? null);

                $tables[$tableName]->addColumn(new DbColumnState(
                    name: $columnName,
                    dataType: $this->extractDataType($columnType),
                    columnType: $columnType,
                    nullable: $pkOrdinal > 0 ? false : $this->intValue($col['notnull'] ?? null) === 0,
                    defaultValue: $this->nullableStringValue($col['dflt_value'] ?? null),
                    isPrimaryKey: $pkOrdinal > 0,
                    isAutoIncrement: false, // SQLite autoincrement is implicit for INTEGER PRIMARY KEY
                    maxLength: null,
                    numericPrecision: null,
                    numericScale: null,
                    comment: '',
                ));
            }

            // Read indexes via PRAGMA index_list
            $indexes = $this->adapter->execute($this->buildPragmaStatement('index_list', $tableName));
            foreach ($indexes->rows as $idx) {
                $indexName = $this->stringValue($idx['name'] ?? null);
                if ($indexName === '') {
                    continue;
                }

                // Skip autoindex (internal indexes for UNIQUE constraints)
                if (str_starts_with($indexName, 'sqlite_autoindex_')) {
                    continue;
                }

                // Get columns for this index
                $indexCols = $this->adapter->execute($this->buildPragmaStatement('index_info', $indexName));
                $colNames = [];
                foreach ($indexCols->rows as $ic) {
                    $indexColumn = $this->stringValue($ic['name'] ?? null);
                    if ($indexColumn !== '') {
                        $colNames[] = $indexColumn;
                    }
                }

                if ($colNames !== []) {
                    $tables[$tableName]->addIndex(new DbIndexState(
                        name: $indexName,
                        columns: $colNames,
                        unique: $this->intValue($idx['unique'] ?? null) === 1,
                    ));
                }
            }
        }

        return $tables;
    }

    /**
     * Extract a normalized data type from SQLite type string.
     *
     * SQLite type declarations can be arbitrary strings. We normalize
     * them to our canonical forms for comparison.
     */
    private function extractDataType(string $type): string
    {
        $type = strtolower(trim($type));

        // Remove parentheses content for base type
        $base = preg_replace('/\([^)]*\)/', '', $type);

        return match (true) {
            in_array($base, ['integer', 'int', 'tinyint', 'smallint', 'mediumint', 'bigint', 'int2', 'int8'], true) => 'integer',
            in_array($base, ['text', 'clob', 'varchar', 'char', 'nvarchar', 'nchar'], true) => 'text',
            in_array($base, ['blob', 'binary', 'varbinary'], true) => 'blob',
            in_array($base, ['real', 'double', 'double precision', 'float', 'decimal', 'numeric', 'number'], true) => 'real',
            default => 'text',
        };
    }

    private function compareTable(TableDefinition $code, DbTableState $db, SchemaDiff $diff): void
    {
        $tableName = $code->name;
        $dbColumns = $db->getColumnMap();
        $codeColumns = $code->getColumns();

        foreach ($codeColumns as $colName => $colDef) {
            if (!isset($dbColumns[$colName])) {
                $diff->addAddColumn($tableName, $colDef);
                continue;
            }

            $dbCol = $dbColumns[$colName];
            $changes = $this->compareColumn($colDef, $dbCol);
            if ($changes !== []) {
                $diff->addAlterColumn($tableName, $colDef, $changes);
            }
        }

        foreach ($dbColumns as $colName => $dbCol) {
            if (!isset($codeColumns[$colName])) {
                $diff->addDropColumn($tableName, $colName, $dbCol->comment, $dbCol);
            }
        }

        $this->compareIndexes($tableName, $code->getIndexes(), $db->getIndexes(), $diff);
    }

    /**
     * @return string[] List of change descriptions
     */
    private function compareColumn(ColumnDefinition $code, DbColumnState $db): array
    {
        $changes = [];

        // Compare SQL type (using canonical name for the type)
        $expectedType = $code->type->canonicalName($code->length, $code->precision, $code->scale);
        $dbTypeNormalized = $this->normalizeDbType($db->columnType);

        if ($this->normalizeType($expectedType) !== $dbTypeNormalized) {
            $changes[] = "type: {$db->columnType} → {$expectedType}";
        }

        // Compare nullable
        if ($code->nullable !== $db->nullable) {
            $nullStr = $code->nullable ? 'NULL' : 'NOT NULL';
            $changes[] = "nullable: " . ($db->nullable ? 'NULL' : 'NOT NULL') . " → {$nullStr}";
        }

        // Compare default value
        // $db->defaultValue is the raw dflt_value from PRAGMA table_info, which includes
        // surrounding quotes for string literals (e.g. '{}' is stored as "'{}'").
        // Normalize both sides so the comparison is quote-agnostic.
        $codeDefault = $this->normalizeDefault($code->default);
        $dbDefault   = $this->normalizeDefault($db->defaultValue);
        if ($codeDefault !== $dbDefault) {
            $fromStr = $db->defaultValue === null ? 'none' : "'{$db->defaultValue}'";
            $toStr   = $codeDefault      === null ? 'none' : "'{$codeDefault}'";
            $changes[] = "default: {$fromStr} → {$toStr}";
        }

        return $changes;
    }

    /**
     * Normalize SQLite DB type for comparison.
     *
     * SQLite may report types in various forms. We normalize to
     * the canonical form used by SqliteType.
     */
    private function normalizeDbType(string $type): string
    {
        $type = strtolower(trim($type));

        // Strip parameters for comparison
        $base = preg_replace('/\([^)]*\)/', '', $type) ?? $type;

        return match ($base) {
            'integer', 'int', 'tinyint', 'smallint', 'bigint' => $base === 'integer' ? 'integer' : 'integer',
            'text', 'varchar', 'char' => 'text',
            'real', 'double', 'float', 'decimal' => 'real',
            'blob', 'binary' => 'blob',
            default => $type,
        };
    }

    private function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        $type = preg_replace('/\(\d+\)/', '', $type) ?? $type;

        return match ($type) {
            'int', 'tinyint', 'smallint', 'bigint', 'integer' => 'integer',
            'varchar', 'char', 'text', 'mediumtext', 'longtext', 'json', 'datetime', 'date', 'time' => 'text',
            'float', 'double', 'decimal', 'real', 'numeric' => 'real',
            'binary', 'blob', 'varbinary' => 'blob',
            default => $type,
        };
    }

    private function normalizeDefault(mixed $default): ?string
    {
        if ($default === null) {
            return null;
        }
        if (is_bool($default)) {
            return $default ? '1' : '0';
        }
        $normalized = $this->stringValue($default);

        if (strlen($normalized) >= 2) {
            $quote = $normalized[0];
            $last = substr($normalized, -1);
            if (($quote === "'" || $quote === '"') && $quote === $last) {
                return substr($normalized, 1, -1);
            }
        }

        return $normalized;
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

        $dbByStructure = [];
        foreach ($dbIndexMap as $name => $dbIdx) {
            $structKey = implode(',', $dbIdx->columns) . '|' . ($dbIdx->unique ? '1' : '0');
            $dbByStructure[$structKey] = $name;
        }

        $matchedDbNames = [];

        foreach ($codeIndexMap as $codeName => $idx) {
            $structKey = implode(',', $idx->columns) . '|' . ($idx->unique ? '1' : '0');

            if (isset($dbIndexMap[$codeName])) {
                $dbIdx = $dbIndexMap[$codeName];
                $matchedDbNames[$codeName] = true;
                if ($idx->columns !== $dbIdx->columns || $idx->unique !== $dbIdx->unique) {
                    $diff->addDropIndex($tableName, $codeName);
                    $diff->addAddIndex($tableName, $idx, $codeName);
                }
            } elseif (isset($dbByStructure[$structKey])) {
                $dbName = $dbByStructure[$structKey];
                $matchedDbNames[$dbName] = true;
                if ($dbName !== $codeName) {
                    $diff->addDropIndex($tableName, $dbName);
                    $diff->addAddIndex($tableName, $idx, $codeName);
                }
            } else {
                $diff->addAddIndex($tableName, $idx, $codeName);
            }
        }

        foreach ($dbIndexMap as $name => $dbIdx) {
            if (!isset($matchedDbNames[$name]) && !isset($this->fkIndexNames[$tableName . '.' . $name])) {
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
     * Compare foreign keys.
     *
     * @param array<string, TableDefinition> $codeSchema
     * @param array<string, array{table: string, column: string, referencedTable: string, referencedColumn: string, onDelete: string, onUpdate: string}> $dbFks
     */
    private function compareForeignKeys(array $codeSchema, SchemaDiff $diff, array $dbFks): void
    {
        $codeFks = [];
        foreach ($codeSchema as $table) {
            foreach ($table->getForeignKeys() as $fk) {
                $codeFks[$fk->constraintName()] = $fk;
            }
        }

        foreach ($codeFks as $name => $fk) {
            if (!isset($dbFks[$name])) {
                if (!isset($this->newTableNames[$fk->table])) {
                    $diff->addForeignKey($fk);
                }
                continue;
            }

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

        foreach ($dbFks as $name => $db) {
            if (!isset($codeFks[$name]) && !in_array($db['table'], $this->ignoreTables, true)) {
                $diff->addDropForeignKey($db['table'], $name);
            }
        }
    }

    /**
     * Read existing FK constraints using PRAGMA foreign_key_list.
     *
     * @return array<string, array{table: string, column: string, referencedTable: string, referencedColumn: string, onDelete: string, onUpdate: string}>
     */
    private function readDbForeignKeys(): array
    {
        $fks = [];

        // Get all tables
        $tables = $this->adapter->execute(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'",
        );

        foreach ($tables->rows as $tableRow) {
            $tableName = $this->stringValue($tableRow['name'] ?? null);
            if ($tableName === '') {
                continue;
            }
            $fkList = $this->adapter->execute($this->buildPragmaStatement('foreign_key_list', $tableName));

            foreach ($fkList->rows as $fk) {
                $from = $this->stringValue($fk['from'] ?? null);
                $referencedTable = $this->stringValue($fk['table'] ?? null);
                if ($from === '' || $referencedTable === '') {
                    continue;
                }

                // SQLite FK names are numeric IDs from PRAGMA
                $constraintName = "fk_{$tableName}_{$from}";
                $fks[$constraintName] = [
                    'table'            => $tableName,
                    'column'           => $from,
                    'referencedTable'  => $referencedTable,
                    'referencedColumn' => $this->stringValue($fk['to'] ?? 'id', 'id'),
                    'onDelete'         => $this->stringValue($fk['on_delete'] ?? 'NO ACTION', 'NO ACTION'),
                    'onUpdate'         => $this->stringValue($fk['on_update'] ?? 'NO ACTION', 'NO ACTION'),
                ];
            }
        }

        return $fks;
    }

    /**
     * Read which index names are used by FK constraints.
     *
     * @return array<string, true>
     */
    private function readFkIndexNames(): array
    {
        $names = [];

        $tables = $this->adapter->execute(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'",
        );

        foreach ($tables->rows as $tableRow) {
            $tableName = $this->stringValue($tableRow['name'] ?? null);
            if ($tableName === '') {
                continue;
            }
            $fkList = $this->adapter->execute($this->buildPragmaStatement('foreign_key_list', $tableName));

            foreach ($fkList->rows as $fk) {
                $fkFrom = $this->stringValue($fk['from'] ?? null);
                if ($fkFrom === '') {
                    continue;
                }

                // Protect only exact single-column FK indexes. Composite indexes that merely
                // include the FK column still need to be droppable when code removes them.
                $indexList = $this->adapter->execute($this->buildPragmaStatement('index_list', $tableName));
                foreach ($indexList->rows as $idx) {
                    $indexName = $this->stringValue($idx['name'] ?? null);
                    if ($indexName === '') {
                        continue;
                    }

                    $indexInfo = $this->adapter->execute($this->buildPragmaStatement('index_info', $indexName));
                    if (count($indexInfo->rows) !== 1) {
                        continue;
                    }

                    $indexedColumn = $this->stringValue($indexInfo->rows[0]['name'] ?? null);
                    if ($indexedColumn === $fkFrom) {
                        $names[$tableName . '.' . $indexName] = true;
                    }
                }
            }
        }

        return $names;
    }

    private function stringValue(mixed $value, string $default = ''): string
    {
        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        return $default;
    }

    private function nullableStringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->stringValue($value);
    }

    private function intValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value) || is_bool($value)) {
            return (int) $value;
        }

        return 0;
    }

    private function buildPragmaStatement(string $pragma, string $identifier): string
    {
        return sprintf('PRAGMA %s(%s)', $pragma, $this->quoteSqliteIdentifier($identifier));
    }

    private function quoteSqliteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}

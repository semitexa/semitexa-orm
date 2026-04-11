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

        $this->fkIndexNames = $this->readFkIndexNames();
        $dbFks = $this->readDbForeignKeys();

        foreach ($codeSchema as $tableName => $tableDefinition) {
            if (!isset($dbSchema[$tableName])) {
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
            $tableName = $row['name'];
            if (in_array($tableName, $this->ignoreTables, true)) {
                continue;
            }

            $tables[$tableName] = new DbTableState($tableName, '');

            // Read columns via PRAGMA table_info
            $columns = $this->adapter->execute("PRAGMA table_info({$tableName})");
            foreach ($columns->rows as $col) {
                $tables[$tableName]->addColumn(new DbColumnState(
                    name: $col['name'],
                    dataType: $this->extractDataType((string) $col['type']),
                    columnType: strtolower((string) $col['type']) ?: 'text',
                    nullable: (int) $col['notnull'] === 0,
                    defaultValue: $col['dflt_value'],
                    isPrimaryKey: (int) $col['pk'] === 1,
                    isAutoIncrement: false, // SQLite autoincrement is implicit for INTEGER PRIMARY KEY
                    maxLength: null,
                    numericPrecision: null,
                    numericScale: null,
                    comment: '',
                ));
            }

            // Read indexes via PRAGMA index_list
            $indexes = $this->adapter->execute("PRAGMA index_list({$tableName})");
            foreach ($indexes->rows as $idx) {
                // Skip autoindex (internal indexes for UNIQUE constraints)
                if (str_starts_with((string) $idx['name'], 'sqlite_autoindex_')) {
                    continue;
                }

                // Get columns for this index
                $indexCols = $this->adapter->execute("PRAGMA index_info({$idx['name']})");
                $colNames = [];
                foreach ($indexCols->rows as $ic) {
                    $colNames[] = $ic['name'];
                }

                if ($colNames !== []) {
                    $tables[$tableName]->addIndex(new DbIndexState(
                        name: $idx['name'],
                        columns: $colNames,
                        unique: (int) $idx['unique'] === 1,
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
        $codeDefault = $this->normalizeDefault($code->default);
        if ($codeDefault !== $db->defaultValue) {
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
        $base = preg_replace('/\([^)]*\)/', '', $type);

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
        $type = preg_replace('/\(\d+\)/', '', $type);
        return $type;
    }

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
                $diff->addForeignKey($fk);
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
            $tableName = $tableRow['name'];
            $fkList = $this->adapter->execute("PRAGMA foreign_key_list({$tableName})");

            foreach ($fkList->rows as $fk) {
                // SQLite FK names are numeric IDs from PRAGMA
                $constraintName = "fk_{$tableName}_{$fk['from']}_{$fk['id']}";
                $fks[$constraintName] = [
                    'table'            => $tableName,
                    'column'           => $fk['from'],
                    'referencedTable'  => $fk['table'],
                    'referencedColumn' => $fk['to'] ?? 'id',
                    'onDelete'         => $fk['on_delete'] ?? 'NO ACTION',
                    'onUpdate'         => $fk['on_update'] ?? 'NO ACTION',
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
            $tableName = $tableRow['name'];
            $fkList = $this->adapter->execute("PRAGMA foreign_key_list({$tableName})");

            foreach ($fkList->rows as $fk) {
                // Find indexes that include this FK column
                $indexList = $this->adapter->execute("PRAGMA index_list({$tableName})");
                foreach ($indexList->rows as $idx) {
                    $indexInfo = $this->adapter->execute("PRAGMA index_info({$idx['name']})");
                    foreach ($indexInfo->rows as $ii) {
                        if ($ii['name'] === $fk['from']) {
                            $names[$tableName . '.' . $idx['name']] = true;
                            break;
                        }
                    }
                }
            }
        }

        return $names;
    }
}

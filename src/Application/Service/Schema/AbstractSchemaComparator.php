<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service\Schema;

use Semitexa\Orm\Domain\Contract\SchemaComparatorInterface;
use Semitexa\Orm\Domain\Model\ColumnDefinition;
use Semitexa\Orm\Domain\Model\DbColumnState;
use Semitexa\Orm\Domain\Model\DbIndexState;
use Semitexa\Orm\Domain\Model\DbTableState;
use Semitexa\Orm\Domain\Model\IndexDefinition;
use Semitexa\Orm\Domain\Model\SchemaDiff;
use Semitexa\Orm\Domain\Model\TableDefinition;

/**
 * The part of schema comparison that does not care which database it is talking to.
 *
 * Reading a live schema is entirely dialect-specific — MySQL asks
 * INFORMATION_SCHEMA, SQLite asks PRAGMA, and their type and default
 * normalisation share nothing. But once both sides are expressed as
 * {@see TableDefinition} and {@see DbTableState}, deciding what changed is the
 * same reasoning: which columns are new, which are gone, which indexes differ,
 * and what an unnamed index would have been called.
 *
 * That shared half was a verbatim copy in {@see SchemaComparator} and
 * {@see SqliteSchemaComparator} — 65 statements, three methods, byte-identical.
 * ep-duplication-sweep moved it here. Everything genuinely per-dialect stayed
 * where it was; only ONE hook is abstract, because only one of these three
 * methods needs to ask a dialect anything.
 */
abstract class AbstractSchemaComparator implements SchemaComparatorInterface
{
    /**
     * Index names the live schema needs kept because a foreign key depends on
     * them, keyed "table.index".
     *
     * Declared here, not on the subclasses, because {@see compareIndexes()}
     * reads it — and a `private` copy on each subclass would be invisible from
     * this class. PHP does not complain about that: `isset()` on a parent's view
     * of a child's private property is simply FALSE, so the guard below would
     * quietly stop protecting anything and the first migration to drop an
     * FK-backing index would fail with MySQL error 1553. Each subclass fills it
     * during compare() from its own readFkIndexNames().
     *
     * @var array<string, bool>
     */
    protected array $fkIndexNames = [];
    protected function compareTable(TableDefinition $code, DbTableState $db, SchemaDiff $diff): void
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
     * @param IndexDefinition[] $codeIndexes
     * @param DbIndexState[] $dbIndexes
     */
    protected function compareIndexes(string $tableName, array $codeIndexes, array $dbIndexes, SchemaDiff $diff): void
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

        // Build structural lookup: "columns|unique" → db index name, for matching by structure
        $dbByStructure = [];
        foreach ($dbIndexMap as $name => $dbIdx) {
            $structKey = implode(',', $dbIdx->columns) . '|' . ($dbIdx->unique ? '1' : '0');
            $dbByStructure[$structKey] = $name;
        }

        $matchedDbNames = [];

        // Indexes in code — match by name first, then by structure
        foreach ($codeIndexMap as $codeName => $idx) {
            $structKey = implode(',', $idx->columns) . '|' . ($idx->unique ? '1' : '0');

            if (isset($dbIndexMap[$codeName])) {
                // Exact name match — compare structure
                $dbIdx = $dbIndexMap[$codeName];
                $matchedDbNames[$codeName] = true;
                if ($idx->columns !== $dbIdx->columns || $idx->unique !== $dbIdx->unique) {
                    $diff->addDropIndex($tableName, $codeName);
                    $diff->addAddIndex($tableName, $idx, $codeName);
                }
            } elseif (isset($dbByStructure[$structKey])) {
                // Same structure exists under a different name — rename (drop old + add new)
                $dbName = $dbByStructure[$structKey];
                $matchedDbNames[$dbName] = true;
                if ($dbName !== $codeName) {
                    $diff->addDropIndex($tableName, $dbName);
                    $diff->addAddIndex($tableName, $idx, $codeName);
                }
            } else {
                // No match at all — new index
                $diff->addAddIndex($tableName, $idx, $codeName);
            }
        }

        // Indexes in DB that were not matched by any code index → DROP
        // But never drop indexes required by FK constraints (MySQL error 1553)
        foreach ($dbIndexMap as $name => $dbIdx) {
            if (!isset($matchedDbNames[$name]) && !isset($this->fkIndexNames[$tableName . '.' . $name])) {
                $diff->addDropIndex($tableName, $name);
            }
        }
    }
    /**
     * @param string[] $columns
     */
    protected function generateIndexName(string $tableName, array $columns, bool $unique): string
    {
        $prefix = $unique ? 'uniq' : 'idx';
        return $prefix . '_' . $tableName . '_' . implode('_', $columns);
    }

    /**
     * Compare one column definition against its live state.
     *
     * The single dialect-dependent step in the shared comparison: what counts as
     * "the same column" differs per engine — MySQL reports `int` where the code
     * says `integer`, SQLite reports affinities rather than declared types, and
     * each has its own idea of how a default is spelled.
     *
     * @return list<string> The change descriptions, empty when the column matches.
     */
    abstract protected function compareColumn(ColumnDefinition $code, DbColumnState $db): array;
}

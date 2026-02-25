<?php

declare(strict_types=1);

namespace Semitexa\Orm\Sync;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Hydration\Hydrator;
use Semitexa\Orm\Schema\ResourceMetadata;

class SmartUpsert
{
    private Hydrator $hydrator;

    public function __construct(
        private readonly DatabaseAdapterInterface $adapter,
        ?Hydrator $hydrator = null,
    ) {
        $this->hydrator = $hydrator ?? new Hydrator();
    }

    /**
     * Upsert a batch of Resource instances by PK using INSERT … ON DUPLICATE KEY UPDATE.
     *
     * A single atomic query per batch — eliminates the SELECT → INSERT race condition
     * where two concurrent processes both see "no row" and both attempt INSERT,
     * causing a duplicate key error. MySQL's ON DUPLICATE KEY UPDATE handles the
     * conflict atomically at the engine level.
     *
     * Returns counts based on MySQL's affected-rows convention:
     *   - 1 affected row  = INSERT (new row)
     *   - 2 affected rows = UPDATE (existing row changed)
     *   - 0 affected rows = no change (existing row identical)
     *
     * @param object[] $resources Resource instances (same class)
     * @return array{inserted: int, updated: int, unchanged: int}
     */
    public function upsert(array $resources): array
    {
        if ($resources === []) {
            return ['inserted' => 0, 'updated' => 0, 'unchanged' => 0];
        }

        $resourceClass = $resources[0]::class;
        $meta          = ResourceMetadata::for($resourceClass);
        $tableName     = $meta->getTableName();
        $pkColumn      = $meta->getPkColumn();

        // Dehydrate all resources; skip any without a PK value.
        $rows = [];
        foreach ($resources as $resource) {
            $data    = $this->hydrator->dehydrate($resource);
            $pkValue = $data[$pkColumn] ?? null;
            if ($pkValue === null) {
                continue;
            }
            $rows[] = $data;
        }

        if ($rows === []) {
            return ['inserted' => 0, 'updated' => 0, 'unchanged' => 0];
        }

        return $this->batchUpsert($tableName, $pkColumn, $rows);
    }

    /**
     * Execute INSERT … ON DUPLICATE KEY UPDATE for all rows in one query.
     *
     * MySQL affected-rows semantics:
     *   1 = row was inserted
     *   2 = row existed and was updated
     *   0 = row existed and was identical (no change)
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array{inserted: int, updated: int, unchanged: int}
     */
    private function batchUpsert(string $table, string $pkColumn, array $rows): array
    {
        $columns   = array_keys($rows[0]);
        $colList   = implode(', ', array_map(fn(string $c) => "`{$c}`", $columns));
        $valueSets = [];
        $params    = [];

        foreach ($rows as $i => $row) {
            $placeholders = [];
            foreach ($columns as $col) {
                $key            = "r{$i}_{$col}";
                $placeholders[] = ":{$key}";
                $params[$key]   = $row[$col] ?? null;
            }
            $valueSets[] = '(' . implode(', ', $placeholders) . ')';
        }

        // Build ON DUPLICATE KEY UPDATE for all non-PK columns.
        $updateClauses = [];
        foreach ($columns as $col) {
            if ($col !== $pkColumn) {
                $updateClauses[] = "`{$col}` = VALUES(`{$col}`)";
            }
        }

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES %s ON DUPLICATE KEY UPDATE %s',
            $table,
            $colList,
            implode(', ', $valueSets),
            implode(', ', $updateClauses),
        );

        $result = $this->adapter->execute($sql, $params);

        // MySQL affected-rows convention for ON DUPLICATE KEY UPDATE:
        //   INSERT (new row)          → +1
        //   UPDATE (row changed)      → +2
        //   no-op  (row identical)    → +0
        //
        // Let I = inserted, U = updated, N = unchanged.
        //   I + U + N = total
        //   affectedRows = I + 2U  →  I = affectedRows - 2U
        //
        // Assuming N = 0 (conservative): U = affectedRows - total, I = total - U.
        // When affectedRows < total some rows were no-ops — we report them as unchanged.
        $affectedRows = $result->rowCount;
        $total        = count($rows);
        $updated      = max(0, $affectedRows - $total);
        $inserted     = max(0, $affectedRows - $updated * 2);
        $unchanged    = max(0, $total - $inserted - $updated);

        return ['inserted' => $inserted, 'updated' => $updated, 'unchanged' => $unchanged];
    }

}

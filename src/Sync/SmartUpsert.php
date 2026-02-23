<?php

declare(strict_types=1);

namespace Semitexa\Orm\Sync;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Hydration\Hydrator;
use Semitexa\Orm\Query\UpdateQuery;
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
     * Upsert a batch of Resource instances by PK.
     * If PK exists in DB and data differs → UPDATE.
     * If PK not in DB → INSERT.
     *
     * Uses a single SELECT … WHERE pk IN (…) to fetch all existing rows,
     * then one batch INSERT for new records, and individual UPDATEs only for
     * records that actually changed. Total queries: 1 + 1 + N_updates
     * instead of the previous 2N per-record approach.
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
        /** @var array<string, array<string, mixed>> $dataByPk  pk → column data */
        $dataByPk = [];
        foreach ($resources as $resource) {
            $data    = $this->hydrator->dehydrate($resource);
            $pkValue = $data[$pkColumn] ?? null;
            if ($pkValue === null) {
                continue;
            }
            $dataByPk[(string) $pkValue] = $data;
        }

        if ($dataByPk === []) {
            return ['inserted' => 0, 'updated' => 0, 'unchanged' => 0];
        }

        // 1. Single SELECT to fetch all existing rows for these PKs.
        $existingByPk = $this->findByPkIn($tableName, $pkColumn, array_keys($dataByPk));

        $toInsert  = [];
        $stats     = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0];

        foreach ($dataByPk as $pkValue => $data) {
            $existing = $existingByPk[$pkValue] ?? null;

            if ($existing === null) {
                $toInsert[] = $data;
            } elseif ($this->hasChanges($data, $existing, $pkColumn)) {
                (new UpdateQuery($tableName, $this->adapter))->execute($data, $pkColumn);
                $stats['updated']++;
            } else {
                $stats['unchanged']++;
            }
        }

        // 2. Batch INSERT for all new records in one query.
        if ($toInsert !== []) {
            $this->batchInsert($tableName, $toInsert);
            $stats['inserted'] = count($toInsert);
        }

        return $stats;
    }

    /**
     * Fetch all rows whose PK is in the given list — single query.
     *
     * @param string[] $pkValues
     * @return array<string, array<string, mixed>>  pk (string) → row
     */
    private function findByPkIn(string $table, string $pkColumn, array $pkValues): array
    {
        if ($pkValues === []) {
            return [];
        }

        $placeholders = [];
        $params       = [];
        foreach ($pkValues as $i => $value) {
            $key                = "pk_{$i}";
            $placeholders[]     = ":{$key}";
            $params[$key]       = $value;
        }

        $inList = implode(', ', $placeholders);
        $result = $this->adapter->execute(
            "SELECT * FROM `{$table}` WHERE `{$pkColumn}` IN ({$inList})",
            $params,
        );

        $map = [];
        foreach ($result->rows as $row) {
            $map[(string) ($row[$pkColumn])] = $row;
        }

        return $map;
    }

    /**
     * Insert multiple rows in a single INSERT … VALUES (…),(…),… statement.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function batchInsert(string $table, array $rows): void
    {
        $columns      = array_keys($rows[0]);
        $colList      = implode(', ', array_map(fn(string $c) => "`{$c}`", $columns));
        $valueSets    = [];
        $params       = [];

        foreach ($rows as $i => $row) {
            $placeholders = [];
            foreach ($columns as $col) {
                $key            = "r{$i}_{$col}";
                $placeholders[] = ":{$key}";
                $params[$key]   = $row[$col] ?? null;
            }
            $valueSets[] = '(' . implode(', ', $placeholders) . ')';
        }

        $sql = "INSERT INTO `{$table}` ({$colList}) VALUES " . implode(', ', $valueSets);
        $this->adapter->execute($sql, $params);
    }

    /**
     * @param array<string, mixed> $newData
     * @param array<string, mixed> $existingRow
     */
    private function hasChanges(array $newData, array $existingRow, string $pkColumn): bool
    {
        foreach ($newData as $col => $value) {
            if ($col === $pkColumn) {
                continue;
            }

            if (!array_key_exists($col, $existingRow)) {
                return true;
            }

            // Cast both to string for comparison (DB returns strings)
            $dbValue = $existingRow[$col];
            if ($this->normalizeForComparison($value) !== $this->normalizeForComparison($dbValue)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeForComparison(mixed $value): string
    {
        if ($value === null) {
            return '__NULL__';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

}

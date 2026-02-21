<?php

declare(strict_types=1);

namespace Semitexa\Orm\Sync;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Hydration\Hydrator;
use Semitexa\Orm\Query\InsertQuery;
use Semitexa\Orm\Query\UpdateQuery;

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
     * @param object[] $resources Resource instances (same class)
     * @return array{inserted: int, updated: int, unchanged: int}
     */
    public function upsert(array $resources): array
    {
        if ($resources === []) {
            return ['inserted' => 0, 'updated' => 0, 'unchanged' => 0];
        }

        $resourceClass = $resources[0]::class;
        $tableName = $this->resolveTableName($resourceClass);
        $pkColumn = $this->resolvePkColumn($resourceClass);

        $stats = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0];

        foreach ($resources as $resource) {
            $data = $this->hydrator->dehydrate($resource);
            $pkValue = $data[$pkColumn] ?? null;

            if ($pkValue === null) {
                continue;
            }

            // Check if record exists
            $existing = $this->findByPk($tableName, $pkColumn, $pkValue);

            if ($existing === null) {
                // INSERT
                (new InsertQuery($tableName, $this->adapter))->execute($data);
                $stats['inserted']++;
            } else {
                // Compare data — update only if changed
                if ($this->hasChanges($data, $existing, $pkColumn)) {
                    (new UpdateQuery($tableName, $this->adapter))->execute($data, $pkColumn);
                    $stats['updated']++;
                } else {
                    $stats['unchanged']++;
                }
            }
        }

        return $stats;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByPk(string $table, string $pkColumn, mixed $pkValue): ?array
    {
        $stmt = $this->adapter->execute(
            "SELECT * FROM `{$table}` WHERE `{$pkColumn}` = :pk LIMIT 1",
            ['pk' => $pkValue],
        );

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
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

    private function resolveTableName(string $resourceClass): string
    {
        $ref = new \ReflectionClass($resourceClass);
        $attrs = $ref->getAttributes(FromTable::class);
        if ($attrs === []) {
            throw new \RuntimeException("Class {$resourceClass} has no #[FromTable] attribute.");
        }
        /** @var FromTable $ft */
        $ft = $attrs[0]->newInstance();
        return $ft->name;
    }

    private function resolvePkColumn(string $resourceClass): string
    {
        $ref = new \ReflectionClass($resourceClass);
        foreach ($ref->getProperties() as $prop) {
            if ($prop->getAttributes(PrimaryKey::class) !== []) {
                return $prop->getName();
            }
        }
        return 'id';
    }
}

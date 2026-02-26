<?php

declare(strict_types=1);

namespace Semitexa\Orm\Hydration;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Schema\ResourceMetadata;

class RelationLoader
{
    public function __construct(
        private readonly DatabaseAdapterInterface $adapter,
        private readonly Hydrator $hydrator,
    ) {}

    /**
     * Load relations for a batch of hydrated Resources.
     * Modifies Resources in-place (sets relation properties).
     *
     * @param object[]    $resources     Hydrated Resource objects (same class)
     * @param class-string $resourceClass
     * @param string[]|null $only        Property names to load; null = load all.
     *                                   Pass an empty array to skip all relations.
     */
    public function loadRelations(array $resources, string $resourceClass, ?array $only = null): void
    {
        if ($resources === []) {
            return;
        }

        $relations = ResourceMetadata::for($resourceClass)->getRelations();

        foreach ($relations as $meta) {
            if ($only !== null && !in_array($meta->property, $only, true)) {
                continue;
            }

            match ($meta->type) {
                RelationType::BelongsTo  => $this->loadBelongsTo($resources, $meta),
                RelationType::HasMany    => $this->loadHasMany($resources, $meta),
                RelationType::OneToOne   => $this->loadOneToOne($resources, $meta),
                RelationType::ManyToMany => $this->loadManyToMany($resources, $meta),
            };
        }
    }

    /**
     * BelongsTo: parent has FK pointing to related table PK.
     * Batch: collect unique FK values, query related table once, distribute.
     */
    private function loadBelongsTo(array $resources, RelationMeta $meta): void
    {
        $fkValues = $this->collectValues($resources, $meta->foreignKey);
        if ($fkValues === []) {
            return;
        }

        $targetMeta  = ResourceMetadata::for($meta->targetClass);
        $targetTable = $targetMeta->getTableName();
        $targetPk    = $targetMeta->getPkColumn();

        $placeholders = $this->buildInPlaceholders($fkValues);
        $sql = "SELECT * FROM `{$targetTable}` WHERE `{$targetPk}` IN ({$placeholders})";
        $result = $this->adapter->execute($sql, array_values($fkValues));
        $rows = $result->rows;

        // Index by PK
        $indexed = [];
        foreach ($rows as $row) {
            $related = $this->hydrator->hydrate($row, $meta->targetClass);
            $indexed[$row[$targetPk]] = $related;
        }

        // Assign to resources
        $prop = new \ReflectionProperty($resources[0]::class, $meta->property);
        $fkProp = new \ReflectionProperty($resources[0]::class, $meta->foreignKey);
        foreach ($resources as $resource) {
            if (!$fkProp->isInitialized($resource)) {
                continue;
            }
            $fk = $fkProp->getValue($resource);
            if (isset($indexed[$fk])) {
                $prop->setValue($resource, $indexed[$fk]);
            }
        }
    }

    /**
     * HasMany: related table has FK pointing to parent PK.
     * Batch: collect parent PKs, query related table once, group by FK.
     */
    private function loadHasMany(array $resources, RelationMeta $meta): void
    {
        $parentPk = ResourceMetadata::for($resources[0]::class)->getPkColumn();
        $pkValues = $this->collectValues($resources, $parentPk);
        if ($pkValues === []) {
            return;
        }

        $targetTable = ResourceMetadata::for($meta->targetClass)->getTableName();

        $placeholders = $this->buildInPlaceholders($pkValues);
        $sql = "SELECT * FROM `{$targetTable}` WHERE `{$meta->foreignKey}` IN ({$placeholders})";
        $result = $this->adapter->execute($sql, array_values($pkValues));
        $rows = $result->rows;

        // Group by FK
        $grouped = [];
        foreach ($rows as $row) {
            $fkVal = $row[$meta->foreignKey];
            $grouped[$fkVal][] = $this->hydrator->hydrate($row, $meta->targetClass);
        }

        // Assign to resources
        $prop = new \ReflectionProperty($resources[0]::class, $meta->property);
        $pkProp = new \ReflectionProperty($resources[0]::class, $parentPk);
        foreach ($resources as $resource) {
            $pk = $pkProp->getValue($resource);
            $prop->setValue($resource, $grouped[$pk] ?? []);
        }
    }

    /**
     * OneToOne: related table has FK pointing to parent PK (single result).
     * Same as HasMany but expects single result per parent.
     */
    private function loadOneToOne(array $resources, RelationMeta $meta): void
    {
        $parentPk = ResourceMetadata::for($resources[0]::class)->getPkColumn();
        $pkValues = $this->collectValues($resources, $parentPk);
        if ($pkValues === []) {
            return;
        }

        $targetTable = ResourceMetadata::for($meta->targetClass)->getTableName();

        $placeholders = $this->buildInPlaceholders($pkValues);
        $sql = "SELECT * FROM `{$targetTable}` WHERE `{$meta->foreignKey}` IN ({$placeholders})";
        $result = $this->adapter->execute($sql, array_values($pkValues));
        $rows = $result->rows;

        // Index by FK (one per parent)
        $indexed = [];
        foreach ($rows as $row) {
            $fkVal = $row[$meta->foreignKey];
            $indexed[$fkVal] = $this->hydrator->hydrate($row, $meta->targetClass);
        }

        // Assign to resources
        $prop = new \ReflectionProperty($resources[0]::class, $meta->property);
        $pkProp = new \ReflectionProperty($resources[0]::class, $parentPk);
        foreach ($resources as $resource) {
            $pk = $pkProp->getValue($resource);
            if (isset($indexed[$pk])) {
                $prop->setValue($resource, $indexed[$pk]);
            }
        }
    }

    /**
     * ManyToMany: pivot table joins parent PK to related PK.
     * Batch: collect parent PKs, query pivot + related in two steps.
     */
    private function loadManyToMany(array $resources, RelationMeta $meta): void
    {
        $parentPk    = ResourceMetadata::for($resources[0]::class)->getPkColumn();
        $pkValues    = $this->collectValues($resources, $parentPk);
        if ($pkValues === []) {
            return;
        }

        $targetMeta  = ResourceMetadata::for($meta->targetClass);
        $targetTable = $targetMeta->getTableName();
        $targetPk    = $targetMeta->getPkColumn();

        // Query pivot table
        $placeholders = $this->buildInPlaceholders($pkValues);
        $pivotSql = "SELECT `{$meta->foreignKey}`, `{$meta->relatedKey}` FROM `{$meta->pivotTable}` WHERE `{$meta->foreignKey}` IN ({$placeholders})";
        $pivotResult = $this->adapter->execute($pivotSql, array_values($pkValues));
        $pivotRows = $pivotResult->rows;

        if ($pivotRows === []) {
            // No relations — set empty arrays
            $prop = new \ReflectionProperty($resources[0]::class, $meta->property);
            foreach ($resources as $resource) {
                $prop->setValue($resource, []);
            }
            return;
        }

        // Collect related IDs and group pivot by parent
        $relatedIds = [];
        $pivotMap = []; // parentPk => [relatedPk, ...]
        foreach ($pivotRows as $row) {
            $parentId = $row[$meta->foreignKey];
            $relatedId = $row[$meta->relatedKey];
            $relatedIds[$relatedId] = $relatedId;
            $pivotMap[$parentId][] = $relatedId;
        }

        // Query related table
        $relPlaceholders = $this->buildInPlaceholders($relatedIds);
        $relSql = "SELECT * FROM `{$targetTable}` WHERE `{$targetPk}` IN ({$relPlaceholders})";
        $relResult = $this->adapter->execute($relSql, array_values($relatedIds));
        $relRows = $relResult->rows;

        // Index related by PK
        $relatedIndex = [];
        foreach ($relRows as $row) {
            $related = $this->hydrator->hydrate($row, $meta->targetClass);
            $relatedIndex[$row[$targetPk]] = $related;
        }

        // Assign to resources
        $prop = new \ReflectionProperty($resources[0]::class, $meta->property);
        $pkProp = new \ReflectionProperty($resources[0]::class, $parentPk);
        foreach ($resources as $resource) {
            $pk = $pkProp->getValue($resource);
            $items = [];
            foreach ($pivotMap[$pk] ?? [] as $relId) {
                if (isset($relatedIndex[$relId])) {
                    $items[] = $relatedIndex[$relId];
                }
            }
            $prop->setValue($resource, $items);
        }
    }

    /**
     * Collect unique non-null values of a property from resources.
     *
     * @return array<int|string, mixed>
     */
    private function collectValues(array $resources, string $propertyName): array
    {
        $values = [];
        $prop = new \ReflectionProperty($resources[0]::class, $propertyName);
        foreach ($resources as $resource) {
            if (!$prop->isInitialized($resource)) {
                continue;
            }
            $val = $prop->getValue($resource);
            if ($val !== null) {
                $values[$val] = $val;
            }
        }
        return $values;
    }

    /**
     * @param array<int|string, mixed> $values
     */
    private function buildInPlaceholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

}

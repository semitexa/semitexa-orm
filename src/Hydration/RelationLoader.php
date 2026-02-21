<?php

declare(strict_types=1);

namespace Semitexa\Orm\Hydration;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Attribute\BelongsTo;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\HasMany;
use Semitexa\Orm\Attribute\ManyToMany;
use Semitexa\Orm\Attribute\OneToOne;
use Semitexa\Orm\Attribute\PrimaryKey;

class RelationLoader
{
    /** @var array<string, RelationMeta[]> Cached relation metadata per class */
    private array $metaCache = [];

    public function __construct(
        private readonly DatabaseAdapterInterface $adapter,
        private readonly Hydrator $hydrator,
    ) {}

    /**
     * Load all declared relations for a batch of hydrated Resources.
     * Modifies Resources in-place (sets relation properties).
     *
     * @param object[] $resources Hydrated Resource objects (same class)
     * @param class-string $resourceClass
     */
    public function loadRelations(array $resources, string $resourceClass): void
    {
        if ($resources === []) {
            return;
        }

        $relations = $this->getRelationMeta($resourceClass);

        foreach ($relations as $meta) {
            match ($meta->type) {
                'belongs_to' => $this->loadBelongsTo($resources, $meta),
                'has_many' => $this->loadHasMany($resources, $meta),
                'one_to_one' => $this->loadOneToOne($resources, $meta),
                'many_to_many' => $this->loadManyToMany($resources, $meta),
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

        $targetTable = $this->resolveTableName($meta->targetClass);
        $targetPk = $this->resolvePkColumn($meta->targetClass);

        $placeholders = $this->buildInPlaceholders($fkValues);
        $sql = "SELECT * FROM `{$targetTable}` WHERE `{$targetPk}` IN ({$placeholders})";
        $stmt = $this->adapter->execute($sql, array_values($fkValues));
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

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
        $parentPk = $this->resolvePkColumn($resources[0]::class);
        $pkValues = $this->collectValues($resources, $parentPk);
        if ($pkValues === []) {
            return;
        }

        $targetTable = $this->resolveTableName($meta->targetClass);

        $placeholders = $this->buildInPlaceholders($pkValues);
        $sql = "SELECT * FROM `{$targetTable}` WHERE `{$meta->foreignKey}` IN ({$placeholders})";
        $stmt = $this->adapter->execute($sql, array_values($pkValues));
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

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
        $parentPk = $this->resolvePkColumn($resources[0]::class);
        $pkValues = $this->collectValues($resources, $parentPk);
        if ($pkValues === []) {
            return;
        }

        $targetTable = $this->resolveTableName($meta->targetClass);

        $placeholders = $this->buildInPlaceholders($pkValues);
        $sql = "SELECT * FROM `{$targetTable}` WHERE `{$meta->foreignKey}` IN ({$placeholders})";
        $stmt = $this->adapter->execute($sql, array_values($pkValues));
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

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
        $parentPk = $this->resolvePkColumn($resources[0]::class);
        $pkValues = $this->collectValues($resources, $parentPk);
        if ($pkValues === []) {
            return;
        }

        $targetTable = $this->resolveTableName($meta->targetClass);
        $targetPk = $this->resolvePkColumn($meta->targetClass);

        // Query pivot table
        $placeholders = $this->buildInPlaceholders($pkValues);
        $pivotSql = "SELECT `{$meta->foreignKey}`, `{$meta->relatedKey}` FROM `{$meta->pivotTable}` WHERE `{$meta->foreignKey}` IN ({$placeholders})";
        $pivotStmt = $this->adapter->execute($pivotSql, array_values($pkValues));
        $pivotRows = $pivotStmt->fetchAll(\PDO::FETCH_ASSOC);

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
        $relStmt = $this->adapter->execute($relSql, array_values($relatedIds));
        $relRows = $relStmt->fetchAll(\PDO::FETCH_ASSOC);

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

    /**
     * @return RelationMeta[]
     */
    private function getRelationMeta(string $resourceClass): array
    {
        if (isset($this->metaCache[$resourceClass])) {
            return $this->metaCache[$resourceClass];
        }

        $relations = [];
        $ref = new \ReflectionClass($resourceClass);

        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            foreach ($prop->getAttributes(BelongsTo::class) as $attr) {
                /** @var BelongsTo $bt */
                $bt = $attr->newInstance();
                $relations[] = new RelationMeta(
                    property: $prop->getName(),
                    type: 'belongs_to',
                    targetClass: $bt->target,
                    foreignKey: $bt->foreignKey,
                );
            }

            foreach ($prop->getAttributes(HasMany::class) as $attr) {
                /** @var HasMany $hm */
                $hm = $attr->newInstance();
                $relations[] = new RelationMeta(
                    property: $prop->getName(),
                    type: 'has_many',
                    targetClass: $hm->target,
                    foreignKey: $hm->foreignKey,
                );
            }

            foreach ($prop->getAttributes(OneToOne::class) as $attr) {
                /** @var OneToOne $oo */
                $oo = $attr->newInstance();
                $relations[] = new RelationMeta(
                    property: $prop->getName(),
                    type: 'one_to_one',
                    targetClass: $oo->target,
                    foreignKey: $oo->foreignKey,
                );
            }

            foreach ($prop->getAttributes(ManyToMany::class) as $attr) {
                /** @var ManyToMany $mm */
                $mm = $attr->newInstance();
                $relations[] = new RelationMeta(
                    property: $prop->getName(),
                    type: 'many_to_many',
                    targetClass: $mm->target,
                    foreignKey: $mm->foreignKey,
                    pivotTable: $mm->pivotTable,
                    relatedKey: $mm->relatedKey,
                );
            }
        }

        $this->metaCache[$resourceClass] = $relations;
        return $relations;
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

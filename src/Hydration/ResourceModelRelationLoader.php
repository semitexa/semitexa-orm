<?php

declare(strict_types=1);

namespace Semitexa\Orm\Hydration;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Metadata\RelationKind;
use Semitexa\Orm\Metadata\RelationMetadata;
use Semitexa\Orm\Metadata\ResourceModelMetadataRegistry;

final class ResourceModelRelationLoader
{
    public function __construct(
        private readonly DatabaseAdapterInterface       $adapter,
        private readonly ResourceModelHydrator          $hydrator,
        private readonly ?ResourceModelMetadataRegistry $metadataRegistry = null,
    ) {}

    /**
     * @param object[] $resourceModels
     * @param class-string $resourceModelClass
     * @param string[]|null $onlyRelations
     */
    public function loadRelations(array $resourceModels, string $resourceModelClass, ?array $onlyRelations = null): void
    {
        if ($resourceModels === []) {
            return;
        }

        $metadata = ($this->metadataRegistry ?? ResourceModelMetadataRegistry::default())->for($resourceModelClass);

        foreach ($metadata->relations() as $relation) {
            if ($onlyRelations !== null && !in_array($relation->propertyName, $onlyRelations, true)) {
                continue;
            }

            match ($relation->kind) {
                RelationKind::BelongsTo => $this->loadBelongsTo($resourceModels, $relation),
                RelationKind::HasMany => $this->loadHasMany($resourceModels, $relation, $resourceModelClass),
                RelationKind::OneToOne => $this->loadOneToOne($resourceModels, $relation, $resourceModelClass),
                RelationKind::ManyToMany => $this->loadManyToMany($resourceModels, $relation, $resourceModelClass),
            };
        }
    }

    /**
     * @param object[] $resourceModels
     */
    private function loadBelongsTo(array $resourceModels, RelationMetadata $relation): void
    {
        $fkValues = $this->collectPropertyValues($resourceModels, $relation->foreignKey);
        if ($fkValues === []) {
            $this->markAllRelationsLoaded($resourceModels, $relation->propertyName, null);
            return;
        }

        $targetMetadata = ($this->metadataRegistry ?? ResourceModelMetadataRegistry::default())->for($relation->targetClass);
        $targetPkProperty = $targetMetadata->primaryKeyProperty ?? 'id';
        $targetPkColumn = $targetMetadata->column($targetPkProperty)->columnName;
        $rows = $this->fetchRowsByColumn($targetMetadata->tableName, $targetPkColumn, array_values($fkValues));

        $indexed = [];
        foreach ($rows as $row) {
            $related = $this->hydrator->hydrate($row, $relation->targetClass);
            $pk = (new \ReflectionProperty($related, $targetPkProperty))->getValue($related);
            $indexed[$pk] = $related;
        }

        foreach ($resourceModels as $resourceModel) {
            $fk = (new \ReflectionProperty($resourceModel, $relation->foreignKey))->getValue($resourceModel);
            $this->relationState($resourceModel, $relation->propertyName)->markLoaded($indexed[$fk] ?? null);
        }
    }

    /**
     * @param object[] $resourceModels
     * @param class-string $parentResourceModelClass
     */
    private function loadHasMany(array $resourceModels, RelationMetadata $relation, string $parentResourceModelClass): void
    {
        $parentMetadata = ($this->metadataRegistry ?? ResourceModelMetadataRegistry::default())->for($parentResourceModelClass);
        $parentPkProperty = $parentMetadata->primaryKeyProperty ?? 'id';
        $parentIds = $this->collectPropertyValues($resourceModels, $parentPkProperty);
        if ($parentIds === []) {
            $this->markAllRelationsLoaded($resourceModels, $relation->propertyName, []);
            return;
        }

        $targetMetadata = ($this->metadataRegistry ?? ResourceModelMetadataRegistry::default())->for($relation->targetClass);
        $fkColumn = $targetMetadata->column($relation->foreignKey)->columnName;
        $rows = $this->fetchRowsByColumn($targetMetadata->tableName, $fkColumn, array_values($parentIds));

        $grouped = [];
        foreach ($rows as $row) {
            $related = $this->hydrator->hydrate($row, $relation->targetClass);
            $fk = (new \ReflectionProperty($related, $relation->foreignKey))->getValue($related);
            $grouped[$fk][] = $related;
        }

        foreach ($resourceModels as $resourceModel) {
            $parentId = (new \ReflectionProperty($resourceModel, $parentPkProperty))->getValue($resourceModel);
            $this->relationState($resourceModel, $relation->propertyName)->markLoaded($grouped[$parentId] ?? []);
        }
    }

    /**
     * @param object[] $resourceModels
     * @param class-string $parentResourceModelClass
     */
    private function loadOneToOne(array $resourceModels, RelationMetadata $relation, string $parentResourceModelClass): void
    {
        $parentMetadata = ($this->metadataRegistry ?? ResourceModelMetadataRegistry::default())->for($parentResourceModelClass);
        $parentPkProperty = $parentMetadata->primaryKeyProperty ?? 'id';
        $parentIds = $this->collectPropertyValues($resourceModels, $parentPkProperty);
        if ($parentIds === []) {
            $this->markAllRelationsLoaded($resourceModels, $relation->propertyName, null);
            return;
        }

        $targetMetadata = ($this->metadataRegistry ?? ResourceModelMetadataRegistry::default())->for($relation->targetClass);
        $fkColumn = $targetMetadata->column($relation->foreignKey)->columnName;
        $rows = $this->fetchRowsByColumn($targetMetadata->tableName, $fkColumn, array_values($parentIds));

        $indexed = [];
        foreach ($rows as $row) {
            $related = $this->hydrator->hydrate($row, $relation->targetClass);
            $fk = (new \ReflectionProperty($related, $relation->foreignKey))->getValue($related);
            $indexed[$fk] = $related;
        }

        foreach ($resourceModels as $resourceModel) {
            $parentId = (new \ReflectionProperty($resourceModel, $parentPkProperty))->getValue($resourceModel);
            $this->relationState($resourceModel, $relation->propertyName)->markLoaded($indexed[$parentId] ?? null);
        }
    }

    /**
     * @param object[] $resourceModels
     * @param class-string $parentResourceModelClass
     */
    private function loadManyToMany(array $resourceModels, RelationMetadata $relation, string $parentResourceModelClass): void
    {
        $parentMetadata = ($this->metadataRegistry ?? ResourceModelMetadataRegistry::default())->for($parentResourceModelClass);
        $parentPkProperty = $parentMetadata->primaryKeyProperty ?? 'id';
        $parentIds = $this->collectPropertyValues($resourceModels, $parentPkProperty);
        if ($parentIds === []) {
            $this->markAllRelationsLoaded($resourceModels, $relation->propertyName, []);
            return;
        }

        $placeholders = $this->buildInPlaceholders($parentIds);
        $pivotSql = sprintf(
            'SELECT `%s`, `%s` FROM `%s` WHERE `%s` IN (%s)',
            $relation->foreignKey,
            $relation->relatedKey,
            $relation->pivotTable,
            $relation->foreignKey,
            $placeholders,
        );
        $pivotRows = $this->adapter->execute($pivotSql, array_values($parentIds))->rows;

        if ($pivotRows === []) {
            $this->markAllRelationsLoaded($resourceModels, $relation->propertyName, []);
            return;
        }

        $targetMetadata = ($this->metadataRegistry ?? ResourceModelMetadataRegistry::default())->for($relation->targetClass);
        $targetPkProperty = $targetMetadata->primaryKeyProperty ?? 'id';
        $targetPkColumn = $targetMetadata->column($targetPkProperty)->columnName;

        $relatedIds = [];
        $pivotMap = [];
        foreach ($pivotRows as $row) {
            $parentId = $row[$relation->foreignKey];
            $relatedId = $row[$relation->relatedKey];
            $relatedIds[$relatedId] = $relatedId;
            $pivotMap[$parentId][] = $relatedId;
        }

        $rows = $this->fetchRowsByColumn($targetMetadata->tableName, $targetPkColumn, array_values($relatedIds));

        $indexed = [];
        foreach ($rows as $row) {
            $related = $this->hydrator->hydrate($row, $relation->targetClass);
            $pk = (new \ReflectionProperty($related, $targetPkProperty))->getValue($related);
            $indexed[$pk] = $related;
        }

        foreach ($resourceModels as $resourceModel) {
            $parentId = (new \ReflectionProperty($resourceModel, $parentPkProperty))->getValue($resourceModel);
            $items = [];
            foreach ($pivotMap[$parentId] ?? [] as $relatedId) {
                if (isset($indexed[$relatedId])) {
                    $items[] = $indexed[$relatedId];
                }
            }
            $this->relationState($resourceModel, $relation->propertyName)->markLoaded($items);
        }
    }

    /**
     * @param object[] $resourceModels
     * @return array<int|string, mixed>
     */
    private function collectPropertyValues(array $resourceModels, string $propertyName): array
    {
        $values = [];
        foreach ($resourceModels as $resourceModel) {
            $property = new \ReflectionProperty($resourceModel, $propertyName);
            if (!$property->isInitialized($resourceModel)) {
                continue;
            }

            $value = $property->getValue($resourceModel);
            if ($value !== null) {
                $values[$value] = $value;
            }
        }

        return $values;
    }

    /**
     * @param list<mixed> $values
     * @return array<int, array<string, mixed>>
     */
    private function fetchRowsByColumn(string $tableName, string $columnName, array $values): array
    {
        $placeholders = $this->buildInPlaceholders($values);
        $sql = sprintf(
            'SELECT * FROM `%s` WHERE `%s` IN (%s)',
            $tableName,
            $columnName,
            $placeholders,
        );

        return $this->adapter->execute($sql, array_values($values))->rows;
    }

    /**
     * @param object[] $resourceModels
     */
    private function markAllRelationsLoaded(array $resourceModels, string $relationProperty, mixed $value): void
    {
        foreach ($resourceModels as $resourceModel) {
            $this->relationState($resourceModel, $relationProperty)->markLoaded($value);
        }
    }

    private function relationState(object $resourceModel, string $relationProperty): RelationState
    {
        $property = new \ReflectionProperty($resourceModel, $relationProperty);
        $current = $property->getValue($resourceModel);

        if (!$current instanceof RelationState) {
            throw new \LogicException(sprintf(
                'Relation property %s::$%s must be initialized as %s before eager loading.',
                $resourceModel::class,
                $relationProperty,
                RelationState::class,
            ));
        }

        return $current;
    }

    /**
     * @param array<int|string, mixed>|list<mixed> $values
     */
    private function buildInPlaceholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Orm\Hydration;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Metadata\RelationKind;
use Semitexa\Orm\Metadata\RelationMetadata;
use Semitexa\Orm\Metadata\TableModelMetadataRegistry;

final class TableModelRelationLoader
{
    public function __construct(
        private readonly DatabaseAdapterInterface $adapter,
        private readonly TableModelHydrator $hydrator,
        private readonly ?TableModelMetadataRegistry $metadataRegistry = null,
    ) {}

    /**
     * @param object[] $tableModels
     * @param class-string $tableModelClass
     * @param string[]|null $onlyRelations
     */
    public function loadRelations(array $tableModels, string $tableModelClass, ?array $onlyRelations = null): void
    {
        if ($tableModels === []) {
            return;
        }

        $metadata = ($this->metadataRegistry ?? TableModelMetadataRegistry::default())->for($tableModelClass);

        foreach ($metadata->relations() as $relation) {
            if ($onlyRelations !== null && !in_array($relation->propertyName, $onlyRelations, true)) {
                continue;
            }

            match ($relation->kind) {
                RelationKind::BelongsTo => $this->loadBelongsTo($tableModels, $relation),
                RelationKind::HasMany => $this->loadHasMany($tableModels, $relation, $tableModelClass),
                RelationKind::OneToOne => $this->loadOneToOne($tableModels, $relation, $tableModelClass),
                RelationKind::ManyToMany => $this->loadManyToMany($tableModels, $relation, $tableModelClass),
            };
        }
    }

    /**
     * @param object[] $tableModels
     */
    private function loadBelongsTo(array $tableModels, RelationMetadata $relation): void
    {
        $fkValues = $this->collectPropertyValues($tableModels, $relation->foreignKey);
        if ($fkValues === []) {
            $this->markAllRelationsLoaded($tableModels, $relation->propertyName, null);
            return;
        }

        $targetMetadata = ($this->metadataRegistry ?? TableModelMetadataRegistry::default())->for($relation->targetClass);
        $targetPkProperty = $targetMetadata->primaryKeyProperty ?? 'id';
        $targetPkColumn = $targetMetadata->column($targetPkProperty)->columnName;
        $rows = $this->fetchRowsByColumn($targetMetadata->tableName, $targetPkColumn, array_values($fkValues));

        $indexed = [];
        foreach ($rows as $row) {
            $related = $this->hydrator->hydrate($row, $relation->targetClass);
            $pk = (new \ReflectionProperty($related, $targetPkProperty))->getValue($related);
            $indexed[$pk] = $related;
        }

        foreach ($tableModels as $tableModel) {
            $fk = (new \ReflectionProperty($tableModel, $relation->foreignKey))->getValue($tableModel);
            $this->relationState($tableModel, $relation->propertyName)->markLoaded($indexed[$fk] ?? null);
        }
    }

    /**
     * @param object[] $tableModels
     * @param class-string $parentTableModelClass
     */
    private function loadHasMany(array $tableModels, RelationMetadata $relation, string $parentTableModelClass): void
    {
        $parentMetadata = ($this->metadataRegistry ?? TableModelMetadataRegistry::default())->for($parentTableModelClass);
        $parentPkProperty = $parentMetadata->primaryKeyProperty ?? 'id';
        $parentIds = $this->collectPropertyValues($tableModels, $parentPkProperty);
        if ($parentIds === []) {
            $this->markAllRelationsLoaded($tableModels, $relation->propertyName, []);
            return;
        }

        $targetMetadata = ($this->metadataRegistry ?? TableModelMetadataRegistry::default())->for($relation->targetClass);
        $fkColumn = $targetMetadata->column($relation->foreignKey)->columnName;
        $rows = $this->fetchRowsByColumn($targetMetadata->tableName, $fkColumn, array_values($parentIds));

        $grouped = [];
        foreach ($rows as $row) {
            $related = $this->hydrator->hydrate($row, $relation->targetClass);
            $fk = (new \ReflectionProperty($related, $relation->foreignKey))->getValue($related);
            $grouped[$fk][] = $related;
        }

        foreach ($tableModels as $tableModel) {
            $parentId = (new \ReflectionProperty($tableModel, $parentPkProperty))->getValue($tableModel);
            $this->relationState($tableModel, $relation->propertyName)->markLoaded($grouped[$parentId] ?? []);
        }
    }

    /**
     * @param object[] $tableModels
     * @param class-string $parentTableModelClass
     */
    private function loadOneToOne(array $tableModels, RelationMetadata $relation, string $parentTableModelClass): void
    {
        $parentMetadata = ($this->metadataRegistry ?? TableModelMetadataRegistry::default())->for($parentTableModelClass);
        $parentPkProperty = $parentMetadata->primaryKeyProperty ?? 'id';
        $parentIds = $this->collectPropertyValues($tableModels, $parentPkProperty);
        if ($parentIds === []) {
            $this->markAllRelationsLoaded($tableModels, $relation->propertyName, null);
            return;
        }

        $targetMetadata = ($this->metadataRegistry ?? TableModelMetadataRegistry::default())->for($relation->targetClass);
        $fkColumn = $targetMetadata->column($relation->foreignKey)->columnName;
        $rows = $this->fetchRowsByColumn($targetMetadata->tableName, $fkColumn, array_values($parentIds));

        $indexed = [];
        foreach ($rows as $row) {
            $related = $this->hydrator->hydrate($row, $relation->targetClass);
            $fk = (new \ReflectionProperty($related, $relation->foreignKey))->getValue($related);
            $indexed[$fk] = $related;
        }

        foreach ($tableModels as $tableModel) {
            $parentId = (new \ReflectionProperty($tableModel, $parentPkProperty))->getValue($tableModel);
            $this->relationState($tableModel, $relation->propertyName)->markLoaded($indexed[$parentId] ?? null);
        }
    }

    /**
     * @param object[] $tableModels
     * @param class-string $parentTableModelClass
     */
    private function loadManyToMany(array $tableModels, RelationMetadata $relation, string $parentTableModelClass): void
    {
        $parentMetadata = ($this->metadataRegistry ?? TableModelMetadataRegistry::default())->for($parentTableModelClass);
        $parentPkProperty = $parentMetadata->primaryKeyProperty ?? 'id';
        $parentIds = $this->collectPropertyValues($tableModels, $parentPkProperty);
        if ($parentIds === []) {
            $this->markAllRelationsLoaded($tableModels, $relation->propertyName, []);
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
            $this->markAllRelationsLoaded($tableModels, $relation->propertyName, []);
            return;
        }

        $targetMetadata = ($this->metadataRegistry ?? TableModelMetadataRegistry::default())->for($relation->targetClass);
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

        foreach ($tableModels as $tableModel) {
            $parentId = (new \ReflectionProperty($tableModel, $parentPkProperty))->getValue($tableModel);
            $items = [];
            foreach ($pivotMap[$parentId] ?? [] as $relatedId) {
                if (isset($indexed[$relatedId])) {
                    $items[] = $indexed[$relatedId];
                }
            }
            $this->relationState($tableModel, $relation->propertyName)->markLoaded($items);
        }
    }

    /**
     * @param object[] $tableModels
     * @return array<int|string, mixed>
     */
    private function collectPropertyValues(array $tableModels, string $propertyName): array
    {
        $values = [];
        foreach ($tableModels as $tableModel) {
            $property = new \ReflectionProperty($tableModel, $propertyName);
            if (!$property->isInitialized($tableModel)) {
                continue;
            }

            $value = $property->getValue($tableModel);
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
     * @param object[] $tableModels
     */
    private function markAllRelationsLoaded(array $tableModels, string $relationProperty, mixed $value): void
    {
        foreach ($tableModels as $tableModel) {
            $this->relationState($tableModel, $relationProperty)->markLoaded($value);
        }
    }

    private function relationState(object $tableModel, string $relationProperty): RelationState
    {
        $property = new \ReflectionProperty($tableModel, $relationProperty);
        $current = $property->getValue($tableModel);

        if (!$current instanceof RelationState) {
            throw new \LogicException(sprintf(
                'Relation property %s::$%s must be initialized as %s before eager loading.',
                $tableModel::class,
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

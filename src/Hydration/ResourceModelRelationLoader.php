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
        /** @var list<int|string> $fkValuesList */
        $fkValuesList = array_keys($fkValues);
        $rows = $this->fetchRowsByColumn($targetMetadata->tableName, $targetPkColumn, $fkValuesList);

        $indexed = [];
        foreach ($rows as $row) {
            $related = $this->hydrator->hydrate($row, $relation->targetClass);
            $pk = $this->arrayKeyFrom((new \ReflectionProperty($related, $targetPkProperty))->getValue($related));
            $indexed[$pk] = $related;
        }

        foreach ($resourceModels as $resourceModel) {
            $fkValue = (new \ReflectionProperty($resourceModel, $relation->foreignKey))->getValue($resourceModel);
            if ($fkValue === null) {
                $this->relationState($resourceModel, $relation->propertyName)->markLoaded(null);
                continue;
            }

            $fk = $this->arrayKeyFrom($fkValue);
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
        /** @var list<int|string> $parentIdsList */
        $parentIdsList = array_keys($parentIds);
        $rows = $this->fetchRowsByColumn($targetMetadata->tableName, $fkColumn, $parentIdsList);

        $grouped = [];
        foreach ($rows as $row) {
            $related = $this->hydrator->hydrate($row, $relation->targetClass);
            $fk = $this->arrayKeyFrom((new \ReflectionProperty($related, $relation->foreignKey))->getValue($related));
            $grouped[$fk][] = $related;
        }

        foreach ($resourceModels as $resourceModel) {
            $parentId = $this->arrayKeyFrom((new \ReflectionProperty($resourceModel, $parentPkProperty))->getValue($resourceModel));
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
        /** @var list<int|string> $parentIdsList */
        $parentIdsList = array_keys($parentIds);
        $rows = $this->fetchRowsByColumn($targetMetadata->tableName, $fkColumn, $parentIdsList);

        $indexed = [];
        foreach ($rows as $row) {
            $related = $this->hydrator->hydrate($row, $relation->targetClass);
            $fk = $this->arrayKeyFrom((new \ReflectionProperty($related, $relation->foreignKey))->getValue($related));
            $indexed[$fk] = $related;
        }

        foreach ($resourceModels as $resourceModel) {
            $parentId = $this->arrayKeyFrom((new \ReflectionProperty($resourceModel, $parentPkProperty))->getValue($resourceModel));
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

        /** @var list<int|string> $parentIdsList */
        $parentIdsList = array_keys($parentIds);
        $parentIdParams = $this->buildInParams($parentIdsList);
        $pivotSql = sprintf(
            'SELECT `%s`, `%s` FROM `%s` WHERE `%s` IN (%s)',
            $relation->foreignKey,
            $relation->relatedKey,
            $relation->pivotTable,
            $relation->foreignKey,
            $this->placeholdersForParams($parentIdParams),
        );
        $pivotRows = $this->adapter->execute($pivotSql, $parentIdParams)->rows;

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
            $parentId = $this->arrayKeyFrom($row[$relation->foreignKey]);
            $relatedId = $this->arrayKeyFrom($row[$relation->relatedKey]);
            $relatedIds[$relatedId] = $relatedId;
            $pivotMap[$parentId][] = $relatedId;
        }

        /** @var list<int|string> $relatedIdsList */
        $relatedIdsList = array_keys($relatedIds);
        $rows = $this->fetchRowsByColumn($targetMetadata->tableName, $targetPkColumn, $relatedIdsList);

        $indexed = [];
        foreach ($rows as $row) {
            $related = $this->hydrator->hydrate($row, $relation->targetClass);
            $pk = $this->arrayKeyFrom((new \ReflectionProperty($related, $targetPkProperty))->getValue($related));
            $indexed[$pk] = $related;
        }

        foreach ($resourceModels as $resourceModel) {
            $parentId = $this->arrayKeyFrom((new \ReflectionProperty($resourceModel, $parentPkProperty))->getValue($resourceModel));
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
                $key = $this->arrayKeyFrom($value);
                $values[$key] = $key;
            }
        }

        return $values;
    }

    /**
     * @param list<int|string> $values
     * @return array<int, array<string, mixed>>
     */
    private function fetchRowsByColumn(string $tableName, string $columnName, array $values): array
    {
        $params = $this->buildInParams($values);
        $sql = sprintf(
            'SELECT * FROM `%s` WHERE `%s` IN (%s)',
            $tableName,
            $columnName,
            $this->placeholdersForParams($params),
        );

        return $this->adapter->execute($sql, $params)->rows;
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
     * @param list<int|string> $values
     * @return array<string, int|string>
     */
    private function buildInParams(array $values): array
    {
        $params = [];

        foreach ($values as $index => $value) {
            $params['in_' . $index] = $value;
        }

        return $params;
    }

    /**
     * @param array<string, int|string> $params
     */
    private function placeholdersForParams(array $params): string
    {
        return implode(', ', array_map(
            static fn (string $key): string => ':' . $key,
            array_keys($params),
        ));
    }

    private function arrayKeyFrom(mixed $value): int|string
    {
        if (is_int($value) || is_string($value)) {
            return $value;
        }

        throw new \LogicException(sprintf(
            'Expected int|string relation key, got %s.',
            get_debug_type($value),
        ));
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Orm\Persistence;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Exception\InvalidRelationWriteException;
use Semitexa\Orm\Hydration\RelationState;
use Semitexa\Orm\Hydration\ResourceModelHydrator;
use Semitexa\Orm\Mapping\MapperRegistry;
use Semitexa\Orm\Metadata\RelationKind;
use Semitexa\Orm\Metadata\RelationMetadata;
use Semitexa\Orm\Metadata\ResourceModelMetadata;
use Semitexa\Orm\Metadata\ResourceModelMetadataRegistry;
use Semitexa\Orm\Uuid\Uuid7;

final class AggregateWriteEngine
{
    public function __construct(
        private readonly DatabaseAdapterInterface       $adapter,
        private readonly ResourceModelHydrator          $hydrator,
        private readonly ?ResourceModelMetadataRegistry $metadataRegistry = null,
    ) {}

    public function insert(object $domainModel, string $resourceModelClass, MapperRegistry $mapperRegistry): object
    {
        $rootResourceModel = $mapperRegistry->mapToSourceModel($domainModel, $resourceModelClass);
        $rootResourceModel = $this->insertResourceModel($rootResourceModel);

        return $mapperRegistry->mapToDomain($rootResourceModel, $domainModel::class);
    }

    public function update(object $domainModel, string $resourceModelClass, MapperRegistry $mapperRegistry): object
    {
        $rootResourceModel = $mapperRegistry->mapToSourceModel($domainModel, $resourceModelClass);
        $this->updateResourceModel($rootResourceModel);

        return $domainModel;
    }

    public function delete(object $domainModel, string $resourceModelClass, MapperRegistry $mapperRegistry): void
    {
        $rootResourceModel = $mapperRegistry->mapToSourceModel($domainModel, $resourceModelClass);
        $this->deleteResourceModel($rootResourceModel);
    }

    private function insertResourceModel(object $resourceModel): object
    {
        $resourceModel = $this->prepareInsertResourceModel($resourceModel);
        $metadata = $this->metadata($resourceModel::class);
        $this->validateReferenceOnlyRelations($resourceModel, $metadata);
        $resourceModel = $this->executeInsert($resourceModel, $metadata);
        $this->persistOwnedRelations($resourceModel, $metadata, true);

        return $resourceModel;
    }

    private function updateResourceModel(object $resourceModel): void
    {
        $metadata = $this->metadata($resourceModel::class);
        $this->validateReferenceOnlyRelations($resourceModel, $metadata);
        $this->executeUpdate($resourceModel, $metadata);
        $this->persistOwnedRelations($resourceModel, $metadata, false);
    }

    private function deleteResourceModel(object $resourceModel): void
    {
        $metadata = $this->metadata($resourceModel::class);
        $this->deleteOwnedRelations($resourceModel, $metadata);
        $this->executeDelete($resourceModel, $metadata);
    }

    private function executeInsert(object $resourceModel, ResourceModelMetadata $metadata): object
    {
        $row = $this->hydrator->dehydrate($resourceModel);
        $columns = array_keys($row);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $metadata->tableName,
            implode(', ', array_map(static fn (string $column): string => sprintf('`%s`', $column), $columns)),
            implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns)),
        );

        $result = $this->adapter->execute($sql, $row);
        $primaryKey = $metadata->primaryKeyProperty;
        if ($primaryKey === null) {
            return $resourceModel;
        }

        $column = $metadata->column($primaryKey);
        if ($column->primaryKeyStrategy !== 'auto') {
            return $resourceModel;
        }

        $currentValue = $this->propertyValue($resourceModel, $primaryKey);
        if ($currentValue !== null && $currentValue !== '') {
            return $resourceModel;
        }

        return $this->withPropertyValue($resourceModel, $primaryKey, (int) $result->lastInsertId);
    }

    private function executeUpdate(object $resourceModel, ResourceModelMetadata $metadata): void
    {
        $primaryKey = $this->requirePrimaryKey($metadata);
        $row = $this->hydrator->dehydrate($resourceModel);
        $pkColumn = $metadata->column($primaryKey)->columnName;
        $pkValue = $row[$pkColumn] ?? $this->propertyValue($resourceModel, $primaryKey);
        unset($row[$pkColumn]);

        $assignments = implode(', ', array_map(
            static fn (string $column): string => sprintf('`%s` = :%s', $column, $column),
            array_keys($row),
        ));

        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE `%s` = :__pk',
            $metadata->tableName,
            $assignments,
            $pkColumn,
        );

        $row['__pk'] = $pkValue;
        $this->adapter->execute($sql, $row);
    }

    private function executeDelete(object $resourceModel, ResourceModelMetadata $metadata): void
    {
        $primaryKey = $this->requirePrimaryKey($metadata);
        $pkColumn = $metadata->column($primaryKey)->columnName;
        $pkValue = $this->propertyValue($resourceModel, $primaryKey);

        $sql = sprintf(
            'DELETE FROM `%s` WHERE `%s` = :__pk',
            $metadata->tableName,
            $pkColumn,
        );

        $this->adapter->execute($sql, ['__pk' => $pkValue]);
    }

    private function persistOwnedRelations(object $resourceModel, ResourceModelMetadata $metadata, bool $isInsert): void
    {
        foreach ($metadata->relations() as $relation) {
            $value = $this->unwrapRelationValue($this->propertyValue($resourceModel, $relation->propertyName));

            if ($relation->writePolicy === RelationWritePolicy::CascadeOwned) {
                $this->persistCascadeOwnedRelation($resourceModel, $metadata, $relation, $value, $isInsert);
                continue;
            }

            if ($relation->writePolicy === RelationWritePolicy::SyncPivotOnly) {
                $this->syncPivotRelation($resourceModel, $metadata, $relation, $value, $isInsert);
            }
        }
    }

    private function deleteOwnedRelations(object $resourceModel, ResourceModelMetadata $metadata): void
    {
        foreach ($metadata->relations() as $relation) {
            if ($relation->writePolicy === RelationWritePolicy::CascadeOwned) {
                $this->deleteCascadeOwnedRelation($resourceModel, $metadata, $relation);
                continue;
            }

            if ($relation->writePolicy === RelationWritePolicy::SyncPivotOnly) {
                $this->syncPivotRelation($resourceModel, $metadata, $relation, [], false);
            }
        }
    }

    private function persistCascadeOwnedRelation(
        object                $resourceModel,
        ResourceModelMetadata $metadata,
        RelationMetadata      $relation,
        mixed                 $value,
        bool                  $isInsert,
    ): void {
        $parentId = $this->propertyValue($resourceModel, $this->requirePrimaryKey($metadata));

        if (!$isInsert) {
            $targetMetadata = $this->metadata($relation->targetClass);
            $fkColumn = $targetMetadata->column($relation->foreignKey)->columnName;
            $sql = sprintf(
                'DELETE FROM `%s` WHERE `%s` = :__parent_fk',
                $targetMetadata->tableName,
                $fkColumn,
            );
            $this->adapter->execute($sql, ['__parent_fk' => $parentId]);
        }

        if ($value === null) {
            return;
        }

        if ($relation->kind === RelationKind::OneToOne) {
            if (!is_object($value)) {
                throw new InvalidRelationWriteException(sprintf(
                    'CascadeOwned relation %s expects an object for one-to-one persistence.',
                    $relation->propertyName,
                ));
            }

            $this->insertResourceModel($this->withPropertyValue($value, $relation->foreignKey, $parentId));
            return;
        }

        foreach ($this->iterableValue($value) as $item) {
            if (!is_object($item)) {
                throw new InvalidRelationWriteException(sprintf(
                    'CascadeOwned relation %s expects resource model objects for cascade persistence.',
                    $relation->propertyName,
                ));
            }

            $this->insertResourceModel($this->withPropertyValue($item, $relation->foreignKey, $parentId));
        }
    }

    private function deleteCascadeOwnedRelation(
        object                $resourceModel,
        ResourceModelMetadata $metadata,
        RelationMetadata      $relation,
    ): void {
        $targetMetadata = $this->metadata($relation->targetClass);
        $fkColumn = $targetMetadata->column($relation->foreignKey)->columnName;
        $parentId = $this->propertyValue($resourceModel, $this->requirePrimaryKey($metadata));

        $sql = sprintf(
            'DELETE FROM `%s` WHERE `%s` = :__parent_fk',
            $targetMetadata->tableName,
            $fkColumn,
        );

        $this->adapter->execute($sql, ['__parent_fk' => $parentId]);
    }

    private function syncPivotRelation(
        object                $resourceModel,
        ResourceModelMetadata $metadata,
        RelationMetadata      $relation,
        mixed                 $value,
        bool                  $isInsert,
    ): void {
        $parentId = $this->propertyValue($resourceModel, $this->requirePrimaryKey($metadata));
        $deleteSql = sprintf(
            'DELETE FROM `%s` WHERE `%s` = :__pivot_fk',
            $relation->pivotTable,
            $relation->foreignKey,
        );
        $this->adapter->execute($deleteSql, ['__pivot_fk' => $parentId]);

        if ($value === null || (!$isInsert && $value === [])) {
            return;
        }

        foreach ($this->iterableValue($value) as $item) {
            $relatedId = $this->extractRelatedIdentifier($item, $relation);
            $insertSql = sprintf(
                'INSERT INTO `%s` (`%s`, `%s`) VALUES (:foreign_key, :related_key)',
                $relation->pivotTable,
                $relation->foreignKey,
                $relation->relatedKey,
            );
            $this->adapter->execute($insertSql, [
                'foreign_key' => $parentId,
                'related_key' => $relatedId,
            ]);
        }
    }

    private function validateReferenceOnlyRelations(object $resourceModel, ResourceModelMetadata $metadata): void
    {
        foreach ($metadata->relations() as $relation) {
            if ($relation->writePolicy !== RelationWritePolicy::ReferenceOnly) {
                continue;
            }

            $value = $this->unwrapRelationValue($this->propertyValue($resourceModel, $relation->propertyName));
            if ($value === null) {
                continue;
            }

            $targetMetadata = $this->metadata($relation->targetClass);
            $targetPrimaryKey = $this->requirePrimaryKey($targetMetadata);
            $relationId = $this->propertyValue($value, $targetPrimaryKey);
            $foreignKeyValue = $this->propertyValue($resourceModel, $relation->foreignKey);

            if ($relationId !== $foreignKeyValue) {
                throw new InvalidRelationWriteException(sprintf(
                    'ReferenceOnly relation %s::$%s points to %s, but foreign key %s is %s.',
                    $resourceModel::class,
                    $relation->propertyName,
                    (string) $relationId,
                    $relation->foreignKey,
                    (string) $foreignKeyValue,
                ));
            }
        }
    }

    private function extractRelatedIdentifier(mixed $item, RelationMetadata $relation): mixed
    {
        if (is_scalar($item)) {
            return $item;
        }

        if (!is_object($item)) {
            throw new InvalidRelationWriteException(sprintf(
                'SyncPivotOnly relation %s expects scalar identifiers or related resource models.',
                $relation->propertyName,
            ));
        }

        $targetMetadata = $this->metadata($relation->targetClass);
        $targetPrimaryKey = $this->requirePrimaryKey($targetMetadata);

        return $this->propertyValue($item, $targetPrimaryKey);
    }

    private function iterableValue(mixed $value): iterable
    {
        if (is_iterable($value)) {
            return $value;
        }

        throw new InvalidRelationWriteException('Expected iterable relation value for aggregate persistence.');
    }

    private function unwrapRelationValue(mixed $value): mixed
    {
        if ($value instanceof RelationState) {
            return $value->isLoaded() ? $value->valueOrNull() : null;
        }

        return $value;
    }

    private function propertyValue(object $object, string $propertyName): mixed
    {
        $property = new \ReflectionProperty($object, $propertyName);

        return $property->getValue($object);
    }

    private function requirePrimaryKey(ResourceModelMetadata $metadata): string
    {
        return $metadata->primaryKeyProperty
            ?? throw new \LogicException(sprintf('Resource model %s has no primary key metadata.', $metadata->className));
    }

    private function metadata(string $resourceModelClass): ResourceModelMetadata
    {
        return ($this->metadataRegistry ?? ResourceModelMetadataRegistry::default())->for($resourceModelClass);
    }

    private function prepareInsertResourceModel(object $resourceModel): object
    {
        $metadata = $this->metadata($resourceModel::class);
        $primaryKey = $metadata->primaryKeyProperty;
        if ($primaryKey === null) {
            return $resourceModel;
        }

        $column = $metadata->column($primaryKey);
        if ($column->primaryKeyStrategy !== 'uuid') {
            return $resourceModel;
        }

        $currentValue = $this->propertyValue($resourceModel, $primaryKey);
        if ($currentValue !== null && $currentValue !== '') {
            return $resourceModel;
        }

        return $this->reconstructWithPropertyOverride(
            $resourceModel,
            $primaryKey,
            Uuid7::generate(),
        );
    }

    private function reconstructWithPropertyOverride(object $resourceModel, string $propertyName, mixed $value): object
    {
        $reflection = new \ReflectionClass($resourceModel);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            throw new \LogicException(sprintf(
                'Cannot override property %s::$%s without a constructor-based resource model.',
                $resourceModel::class,
                $propertyName,
            ));
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            if ($name === $propertyName) {
                $arguments[] = $value;
                continue;
            }

            $property = new \ReflectionProperty($resourceModel, $name);
            $arguments[] = $property->getValue($resourceModel);
        }

        return $reflection->newInstanceArgs($arguments);
    }

    private function withPropertyValue(object $resourceModel, string $propertyName, mixed $value): object
    {
        $reflection = new \ReflectionClass($resourceModel);

        if ($reflection->isReadOnly()) {
            return $this->reconstructWithPropertyOverride($resourceModel, $propertyName, $value);
        }

        $property = new \ReflectionProperty($resourceModel, $propertyName);
        $property->setValue($resourceModel, $value);

        return $resourceModel;
    }
}

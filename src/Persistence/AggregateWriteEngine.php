<?php

declare(strict_types=1);

namespace Semitexa\Orm\Persistence;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Exception\InvalidRelationWriteException;
use Semitexa\Orm\Hydration\RelationState;
use Semitexa\Orm\Hydration\TableModelHydrator;
use Semitexa\Orm\Mapping\MapperRegistry;
use Semitexa\Orm\Metadata\RelationKind;
use Semitexa\Orm\Metadata\RelationMetadata;
use Semitexa\Orm\Metadata\TableModelMetadata;
use Semitexa\Orm\Metadata\TableModelMetadataRegistry;
use Semitexa\Orm\Uuid\Uuid7;

final class AggregateWriteEngine
{
    public function __construct(
        private readonly DatabaseAdapterInterface $adapter,
        private readonly TableModelHydrator $hydrator,
        private readonly ?TableModelMetadataRegistry $metadataRegistry = null,
    ) {}

    public function insert(object $domainModel, string $tableModelClass, MapperRegistry $mapperRegistry): object
    {
        $rootTableModel = $mapperRegistry->mapToTableModel($domainModel, $tableModelClass);
        $rootTableModel = $this->insertTableModel($rootTableModel);

        return $mapperRegistry->mapToDomain($rootTableModel, $domainModel::class);
    }

    public function update(object $domainModel, string $tableModelClass, MapperRegistry $mapperRegistry): object
    {
        $rootTableModel = $mapperRegistry->mapToTableModel($domainModel, $tableModelClass);
        $this->updateTableModel($rootTableModel);

        return $domainModel;
    }

    public function delete(object $domainModel, string $tableModelClass, MapperRegistry $mapperRegistry): void
    {
        $rootTableModel = $mapperRegistry->mapToTableModel($domainModel, $tableModelClass);
        $this->deleteTableModel($rootTableModel);
    }

    private function insertTableModel(object $tableModel): object
    {
        $tableModel = $this->prepareInsertTableModel($tableModel);
        $metadata = $this->metadata($tableModel::class);
        $this->validateReferenceOnlyRelations($tableModel, $metadata);
        $tableModel = $this->executeInsert($tableModel, $metadata);
        $this->persistOwnedRelations($tableModel, $metadata, true);

        return $tableModel;
    }

    private function updateTableModel(object $tableModel): void
    {
        $metadata = $this->metadata($tableModel::class);
        $this->validateReferenceOnlyRelations($tableModel, $metadata);
        $this->executeUpdate($tableModel, $metadata);
        $this->persistOwnedRelations($tableModel, $metadata, false);
    }

    private function deleteTableModel(object $tableModel): void
    {
        $metadata = $this->metadata($tableModel::class);
        $this->deleteOwnedRelations($tableModel, $metadata);
        $this->executeDelete($tableModel, $metadata);
    }

    private function executeInsert(object $tableModel, TableModelMetadata $metadata): object
    {
        $row = $this->hydrator->dehydrate($tableModel);
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
            return $tableModel;
        }

        $column = $metadata->column($primaryKey);
        if ($column->primaryKeyStrategy !== 'auto') {
            return $tableModel;
        }

        $currentValue = $this->propertyValue($tableModel, $primaryKey);
        if ($currentValue !== null && $currentValue !== '') {
            return $tableModel;
        }

        return $this->withPropertyValue($tableModel, $primaryKey, (int) $result->lastInsertId);
    }

    private function executeUpdate(object $tableModel, TableModelMetadata $metadata): void
    {
        $primaryKey = $this->requirePrimaryKey($metadata);
        $row = $this->hydrator->dehydrate($tableModel);
        $pkColumn = $metadata->column($primaryKey)->columnName;
        $pkValue = $row[$pkColumn] ?? $this->propertyValue($tableModel, $primaryKey);
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

    private function executeDelete(object $tableModel, TableModelMetadata $metadata): void
    {
        $primaryKey = $this->requirePrimaryKey($metadata);
        $pkColumn = $metadata->column($primaryKey)->columnName;
        $pkValue = $this->propertyValue($tableModel, $primaryKey);

        $sql = sprintf(
            'DELETE FROM `%s` WHERE `%s` = :__pk',
            $metadata->tableName,
            $pkColumn,
        );

        $this->adapter->execute($sql, ['__pk' => $pkValue]);
    }

    private function persistOwnedRelations(object $tableModel, TableModelMetadata $metadata, bool $isInsert): void
    {
        foreach ($metadata->relations() as $relation) {
            $value = $this->unwrapRelationValue($this->propertyValue($tableModel, $relation->propertyName));

            if ($relation->writePolicy === RelationWritePolicy::CascadeOwned) {
                $this->persistCascadeOwnedRelation($tableModel, $metadata, $relation, $value, $isInsert);
                continue;
            }

            if ($relation->writePolicy === RelationWritePolicy::SyncPivotOnly) {
                $this->syncPivotRelation($tableModel, $metadata, $relation, $value, $isInsert);
            }
        }
    }

    private function deleteOwnedRelations(object $tableModel, TableModelMetadata $metadata): void
    {
        foreach ($metadata->relations() as $relation) {
            if ($relation->writePolicy === RelationWritePolicy::CascadeOwned) {
                $this->deleteCascadeOwnedRelation($tableModel, $metadata, $relation);
                continue;
            }

            if ($relation->writePolicy === RelationWritePolicy::SyncPivotOnly) {
                $this->syncPivotRelation($tableModel, $metadata, $relation, [], false);
            }
        }
    }

    private function persistCascadeOwnedRelation(
        object $tableModel,
        TableModelMetadata $metadata,
        RelationMetadata $relation,
        mixed $value,
        bool $isInsert,
    ): void {
        $parentId = $this->propertyValue($tableModel, $this->requirePrimaryKey($metadata));

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

            $this->insertTableModel($this->withPropertyValue($value, $relation->foreignKey, $parentId));
            return;
        }

        foreach ($this->iterableValue($value) as $item) {
            if (!is_object($item)) {
                throw new InvalidRelationWriteException(sprintf(
                    'CascadeOwned relation %s expects table model objects for cascade persistence.',
                    $relation->propertyName,
                ));
            }

            $this->insertTableModel($this->withPropertyValue($item, $relation->foreignKey, $parentId));
        }
    }

    private function deleteCascadeOwnedRelation(
        object $tableModel,
        TableModelMetadata $metadata,
        RelationMetadata $relation,
    ): void {
        $targetMetadata = $this->metadata($relation->targetClass);
        $fkColumn = $targetMetadata->column($relation->foreignKey)->columnName;
        $parentId = $this->propertyValue($tableModel, $this->requirePrimaryKey($metadata));

        $sql = sprintf(
            'DELETE FROM `%s` WHERE `%s` = :__parent_fk',
            $targetMetadata->tableName,
            $fkColumn,
        );

        $this->adapter->execute($sql, ['__parent_fk' => $parentId]);
    }

    private function syncPivotRelation(
        object $tableModel,
        TableModelMetadata $metadata,
        RelationMetadata $relation,
        mixed $value,
        bool $isInsert,
    ): void {
        $parentId = $this->propertyValue($tableModel, $this->requirePrimaryKey($metadata));
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

    private function validateReferenceOnlyRelations(object $tableModel, TableModelMetadata $metadata): void
    {
        foreach ($metadata->relations() as $relation) {
            if ($relation->writePolicy !== RelationWritePolicy::ReferenceOnly) {
                continue;
            }

            $value = $this->unwrapRelationValue($this->propertyValue($tableModel, $relation->propertyName));
            if ($value === null) {
                continue;
            }

            $targetMetadata = $this->metadata($relation->targetClass);
            $targetPrimaryKey = $this->requirePrimaryKey($targetMetadata);
            $relationId = $this->propertyValue($value, $targetPrimaryKey);
            $foreignKeyValue = $this->propertyValue($tableModel, $relation->foreignKey);

            if ($relationId !== $foreignKeyValue) {
                throw new InvalidRelationWriteException(sprintf(
                    'ReferenceOnly relation %s::$%s points to %s, but foreign key %s is %s.',
                    $tableModel::class,
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
                'SyncPivotOnly relation %s expects scalar identifiers or related table models.',
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

    private function requirePrimaryKey(TableModelMetadata $metadata): string
    {
        return $metadata->primaryKeyProperty
            ?? throw new \LogicException(sprintf('Table model %s has no primary key metadata.', $metadata->className));
    }

    private function metadata(string $tableModelClass): TableModelMetadata
    {
        return ($this->metadataRegistry ?? TableModelMetadataRegistry::default())->for($tableModelClass);
    }

    private function prepareInsertTableModel(object $tableModel): object
    {
        $metadata = $this->metadata($tableModel::class);
        $primaryKey = $metadata->primaryKeyProperty;
        if ($primaryKey === null) {
            return $tableModel;
        }

        $column = $metadata->column($primaryKey);
        if ($column->primaryKeyStrategy !== 'uuid') {
            return $tableModel;
        }

        $currentValue = $this->propertyValue($tableModel, $primaryKey);
        if ($currentValue !== null && $currentValue !== '') {
            return $tableModel;
        }

        return $this->reconstructWithPropertyOverride(
            $tableModel,
            $primaryKey,
            Uuid7::generate(),
        );
    }

    private function reconstructWithPropertyOverride(object $tableModel, string $propertyName, mixed $value): object
    {
        $reflection = new \ReflectionClass($tableModel);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            throw new \LogicException(sprintf(
                'Cannot override property %s::$%s without a constructor-based table model.',
                $tableModel::class,
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

            $property = new \ReflectionProperty($tableModel, $name);
            $arguments[] = $property->getValue($tableModel);
        }

        return $reflection->newInstanceArgs($arguments);
    }

    private function withPropertyValue(object $tableModel, string $propertyName, mixed $value): object
    {
        $reflection = new \ReflectionClass($tableModel);

        if ($reflection->isReadOnly()) {
            return $this->reconstructWithPropertyOverride($tableModel, $propertyName, $value);
        }

        $property = new \ReflectionProperty($tableModel, $propertyName);
        $property->setValue($tableModel, $value);

        return $tableModel;
    }
}

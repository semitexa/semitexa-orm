<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service\Persistence;

use Semitexa\Core\Event\EventDispatcherInterface;
use Semitexa\Orm\Domain\Enum\RelationWritePolicy;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Exception\InvalidRelationWriteException;
use Semitexa\Orm\Exception\StaleAggregateException;
use Semitexa\Orm\Domain\Enum\ResourceChangeOperation;
use Semitexa\Orm\Domain\Event\ResourceChangedEvent;
use Semitexa\Orm\Domain\Model\RelationState;
use Semitexa\Orm\Domain\Model\ResourceMetadata;
use Semitexa\Orm\Application\Service\Hydration\ResourceModelHydrator;
use Semitexa\Orm\Application\Service\Mapping\MapperRegistry;
use Semitexa\Orm\Metadata\RelationKind;
use Semitexa\Orm\Metadata\RelationMetadata;
use Semitexa\Orm\Query\DeleteQuery;
use Semitexa\Orm\Query\InsertQuery;
use Semitexa\Orm\Metadata\ResourceModelMetadata;
use Semitexa\Orm\Metadata\ResourceModelMetadataRegistry;
use Semitexa\Orm\Application\Service\Transaction\TransactionManager;
use Semitexa\Orm\Application\Service\Uuid7;

final class AggregateWriteEngine
{
    /** Max rows per batched pivot INSERT — keeps bound params well under driver limits. */
    private const PIVOT_INSERT_CHUNK = 500;

    /**
     * `$events` accepts a lazy `\Closure(): ?EventDispatcherInterface` besides a
     * concrete dispatcher: engines are memoized (OrmManager, DomainRepository),
     * so a dispatcher captured at construction freezes whatever was resolvable
     * at that moment. In a CLI worker (scheduler:work) the first ORM write is
     * the scheduler's own bookkeeping — long before any job registers the
     * dispatcher resolver — which silently killed auto-publish for every write
     * that followed. A closure re-resolves at each dispatch instead.
     *
     * `$transactions` accepts the same lazy-closure shape for the same reason:
     * the memoized engine must not freeze a TransactionManager built over a
     * pool that OrmManager later self-heals/swaps — resolve per write. When
     * null (hand-built engines in tests), writes run on the bare adapter with
     * NO transaction, i.e. the legacy non-atomic behaviour.
     */
    public function __construct(
        private readonly DatabaseAdapterInterface                  $adapter,
        private readonly ResourceModelHydrator                     $hydrator,
        private readonly ?ResourceModelMetadataRegistry            $metadataRegistry = null,
        private readonly EventDispatcherInterface|\Closure|null    $events = null,
        private readonly TransactionManager|\Closure|null          $transactions = null,
    ) {}

    /**
     * Run one aggregate write atomically. The whole statement series (root
     * row + cascade-owned children + pivot sync) either commits or rolls
     * back — a mid-cascade failure can no longer leave partial rows behind.
     *
     * The work receives the TRANSACTION'S adapter: TransactionManager::run
     * binds a dedicated connection, and statements issued on the engine's own
     * pooled adapter would bypass the BEGIN entirely. Nested calls (a caller
     * already inside TransactionManager::run) become savepoints.
     *
     * @template T
     * @param callable(DatabaseAdapterInterface): T $work
     * @return T
     */
    private function atomically(callable $work): mixed
    {
        $transactions = $this->transactions instanceof \Closure
            ? ($this->transactions)()
            : $this->transactions;

        if ($transactions === null) {
            return $work($this->adapter);
        }

        return $transactions->run($work);
    }

    /**
     * @param class-string $resourceModelClass
     */
    public function insert(object $domainModel, string $resourceModelClass, MapperRegistry $mapperRegistry): object
    {
        $rootResourceModel = $mapperRegistry->mapToSourceModel($domainModel, $resourceModelClass);
        $rootResourceModel = $this->atomically(
            fn (DatabaseAdapterInterface $adapter): object => $this->insertResourceModel($rootResourceModel, $adapter),
        );
        $domainResult = $mapperRegistry->mapToDomain($rootResourceModel, $domainModel::class);

        $this->dispatchResourceChanged($resourceModelClass, ResourceChangeOperation::Insert);

        return $domainResult;
    }

    /**
     * @param class-string $resourceModelClass
     */
    public function update(object $domainModel, string $resourceModelClass, MapperRegistry $mapperRegistry): object
    {
        $rootResourceModel = $mapperRegistry->mapToSourceModel($domainModel, $resourceModelClass);
        $updatedResourceModel = $this->atomically(
            fn (DatabaseAdapterInterface $adapter): object => $this->updateResourceModel($rootResourceModel, $adapter),
        );

        $this->dispatchResourceChanged($resourceModelClass, ResourceChangeOperation::Update);

        // On a #[Version] resource the row now carries version+1 — return the
        // BUMPED domain so `update($x); update($x);` keeps working. Returning
        // the input here would self-stale every sequential update. Keyed off
        // metadata, NOT object identity: a non-readonly resource is bumped
        // in place (same instance), which an identity check would miss.
        if ($this->metadata($resourceModelClass)->versionProperty !== null
            || $updatedResourceModel !== $rootResourceModel
        ) {
            return $mapperRegistry->mapToDomain($updatedResourceModel, $domainModel::class);
        }

        return $domainModel;
    }

    /**
     * @param class-string $resourceModelClass
     */
    public function delete(object $domainModel, string $resourceModelClass, MapperRegistry $mapperRegistry): void
    {
        $rootResourceModel = $mapperRegistry->mapToSourceModel($domainModel, $resourceModelClass);
        $this->atomically(function (DatabaseAdapterInterface $adapter) use ($rootResourceModel): void {
            $this->deleteResourceModel($rootResourceModel, $adapter);
        });

        $this->dispatchResourceChanged($resourceModelClass, ResourceChangeOperation::Delete);
    }

    /**
     * Emit one data-less, scope-keyed {@see ResourceChangedEvent} per aggregate-root
     * write. This is the single post-write chokepoint: exactly one event per
     * insert/update/delete, keyed by the root resource's `resourceKey` (P1) — the
     * nested owned-relation writes do NOT each fire (one signal per aggregate op).
     *
     * Dispatch is strictly additive and isolated: with no dispatcher bound it is a
     * no-op, and a missing/failing listener must never corrupt or roll back the
     * already-completed write. The invalidation signal is best-effort and
     * lossy-tolerant by design (track-r-design.md §C.3), so a swallowed failure
     * costs at most one missed re-query, repaired by the next mutation's signal.
     *
     * @param class-string $resourceModelClass
     */
    private function dispatchResourceChanged(string $resourceModelClass, ResourceChangeOperation $operation): void
    {
        try {
            $dispatcher = $this->events instanceof \Closure ? ($this->events)() : $this->events;
            if ($dispatcher === null) {
                return;
            }

            $resourceKey = ResourceMetadata::for($resourceModelClass)->getResourceKey();
            $event = new ResourceChangedEvent($resourceKey, $operation);

            // Commit-gate: when this write nested inside a CALLER transaction
            // (our atomically() became a savepoint and has already returned,
            // but the outer tx is still open), an immediate dispatch would let
            // subscribers re-query PRE-COMMIT state — and an outer rollback
            // would have signalled a change that never existed. Buffer on the
            // TransactionManager instead; it flushes after the outer commit
            // and clears on rollback. Late-wire the dispatcher so the flush
            // can actually deliver.
            $transactions = $this->transactions instanceof \Closure
                ? ($this->transactions)()
                : $this->transactions;
            if ($transactions !== null && $transactions->isActive()) {
                $transactions->setEventDispatcher($dispatcher);
                $transactions->bufferEvent($event);
                return;
            }

            $dispatcher->dispatch($event);
        } catch (\Throwable) {
            // Intentionally swallowed: the write has already succeeded. Invalidation
            // is a best-effort, data-less signal — never break the write path on it.
        }
    }

    private function insertResourceModel(object $resourceModel, DatabaseAdapterInterface $adapter): object
    {
        $resourceModel = $this->prepareInsertResourceModel($resourceModel);
        $metadata = $this->metadata($resourceModel::class);
        $this->validateReferenceOnlyRelations($resourceModel, $metadata);
        $resourceModel = $this->executeInsert($resourceModel, $metadata, $adapter);
        $this->persistOwnedRelations($resourceModel, $metadata, true, $adapter);

        return $resourceModel;
    }

    /** @return object the resource model as persisted (version-bumped when #[Version] applies) */
    private function updateResourceModel(object $resourceModel, DatabaseAdapterInterface $adapter): object
    {
        $metadata = $this->metadata($resourceModel::class);
        $this->validateReferenceOnlyRelations($resourceModel, $metadata);
        $resourceModel = $this->executeUpdate($resourceModel, $metadata, $adapter);
        $this->persistOwnedRelations($resourceModel, $metadata, false, $adapter);

        return $resourceModel;
    }

    private function deleteResourceModel(object $resourceModel, DatabaseAdapterInterface $adapter): void
    {
        $metadata = $this->metadata($resourceModel::class);
        $this->deleteOwnedRelations($resourceModel, $metadata, $adapter);
        $this->executeDelete($resourceModel, $metadata, $adapter);
    }

    private function executeInsert(object $resourceModel, ResourceModelMetadata $metadata, DatabaseAdapterInterface $adapter): object
    {
        $row = $this->hydrator->dehydrate($resourceModel);
        $columns = array_keys($row);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $metadata->tableName,
            implode(', ', array_map(static fn (string $column): string => sprintf('`%s`', $column), $columns)),
            implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns)),
        );

        $result = $adapter->execute($sql, $row);
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

    /** @return object the input model, version-bumped when #[Version] applies */
    private function executeUpdate(object $resourceModel, ResourceModelMetadata $metadata, DatabaseAdapterInterface $adapter): object
    {
        $primaryKey = $this->requirePrimaryKey($metadata);
        $row = $this->hydrator->dehydrate($resourceModel);
        $pkColumn = $metadata->column($primaryKey)->columnName;
        $pkValue = $row[$pkColumn] ?? $this->propertyValue($resourceModel, $primaryKey);
        unset($row[$pkColumn]);

        // Optimistic locking: guard on the #[Version] the caller READ and bump
        // it in the same statement. A concurrent writer that committed first
        // makes the guard miss -> zero affected rows -> StaleAggregateException
        // instead of a silent lost update.
        $versionGuard = '';
        $expectedVersion = null;
        if ($metadata->versionProperty !== null) {
            $versionColumn = $metadata->column($metadata->versionProperty)->columnName;
            $expectedVersion = $row[$versionColumn] ?? null;
            if (!is_numeric($expectedVersion)) {
                throw new \LogicException(sprintf(
                    '#[Version] property %s::$%s must carry the read version (int) on update.',
                    $resourceModel::class,
                    $metadata->versionProperty,
                ));
            }
            $row[$versionColumn] = (int) $expectedVersion + 1;
            $versionGuard = sprintf(' AND `%s` = :__expected_version', $versionColumn);
        }

        $assignments = implode(', ', array_map(
            static fn (string $column): string => sprintf('`%s` = :%s', $column, $column),
            array_keys($row),
        ));

        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE `%s` = :__pk%s',
            $metadata->tableName,
            $assignments,
            $pkColumn,
            $versionGuard,
        );

        $row['__pk'] = $pkValue;
        if ($versionGuard !== '') {
            $row['__expected_version'] = (int) $expectedVersion;
        }
        $result = $adapter->execute($sql, $row);

        if ($versionGuard !== '' && $result->rowCount === 0) {
            throw new StaleAggregateException(sprintf(
                'Optimistic-lock miss updating %s (pk %s, expected version %d): the row was modified concurrently or no longer exists. Re-read and retry.',
                $metadata->tableName,
                (string) $pkValue,
                (int) $expectedVersion,
            ));
        }

        if ($metadata->versionProperty !== null) {
            return $this->withPropertyValue($resourceModel, $metadata->versionProperty, (int) $expectedVersion + 1);
        }

        return $resourceModel;
    }

    private function executeDelete(object $resourceModel, ResourceModelMetadata $metadata, DatabaseAdapterInterface $adapter): void
    {
        $primaryKey = $this->requirePrimaryKey($metadata);
        $pkColumn = $metadata->column($primaryKey)->columnName;
        $pkValue = $this->propertyValue($resourceModel, $primaryKey);

        (new DeleteQuery($metadata->tableName, $adapter))->execute($pkColumn, $pkValue);
    }

    private function persistOwnedRelations(object $resourceModel, ResourceModelMetadata $metadata, bool $isInsert, DatabaseAdapterInterface $adapter): void
    {
        foreach ($metadata->relations() as $relation) {
            $value = $this->unwrapRelationValue($this->propertyValue($resourceModel, $relation->propertyName));

            if ($relation->writePolicy === RelationWritePolicy::CascadeOwned) {
                $this->persistCascadeOwnedRelation($resourceModel, $metadata, $relation, $value, $isInsert, $adapter);
                continue;
            }

            if ($relation->writePolicy === RelationWritePolicy::SyncPivotOnly) {
                $this->syncPivotRelation($resourceModel, $metadata, $relation, $value, $isInsert, $adapter);
            }
        }
    }

    private function deleteOwnedRelations(object $resourceModel, ResourceModelMetadata $metadata, DatabaseAdapterInterface $adapter): void
    {
        foreach ($metadata->relations() as $relation) {
            if ($relation->writePolicy === RelationWritePolicy::CascadeOwned) {
                $this->deleteCascadeOwnedRelation($resourceModel, $metadata, $relation, $adapter);
                continue;
            }

            if ($relation->writePolicy === RelationWritePolicy::SyncPivotOnly) {
                $this->syncPivotRelation($resourceModel, $metadata, $relation, [], false, $adapter);
            }
        }
    }

    private function persistCascadeOwnedRelation(
        object                $resourceModel,
        ResourceModelMetadata $metadata,
        RelationMetadata      $relation,
        mixed                 $value,
        bool                  $isInsert,
        DatabaseAdapterInterface $adapter,
    ): void {
        $parentId = $this->propertyValue($resourceModel, $this->requirePrimaryKey($metadata));

        if (!$isInsert) {
            $targetMetadata = $this->metadata($relation->targetClass);
            $fkColumn = $targetMetadata->column($relation->foreignKey)->columnName;
            (new DeleteQuery($targetMetadata->tableName, $adapter))->execute($fkColumn, $parentId);
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

            $this->insertResourceModel($this->withPropertyValue($value, $relation->foreignKey, $parentId), $adapter);
            return;
        }

        foreach ($this->iterableValue($value) as $item) {
            if (!is_object($item)) {
                throw new InvalidRelationWriteException(sprintf(
                    'CascadeOwned relation %s expects resource model objects for cascade persistence.',
                    $relation->propertyName,
                ));
            }

            $this->insertResourceModel($this->withPropertyValue($item, $relation->foreignKey, $parentId), $adapter);
        }
    }

    private function deleteCascadeOwnedRelation(
        object                $resourceModel,
        ResourceModelMetadata $metadata,
        RelationMetadata      $relation,
        DatabaseAdapterInterface $adapter,
    ): void {
        $targetMetadata = $this->metadata($relation->targetClass);
        $fkColumn = $targetMetadata->column($relation->foreignKey)->columnName;
        $parentId = $this->propertyValue($resourceModel, $this->requirePrimaryKey($metadata));

        (new DeleteQuery($targetMetadata->tableName, $adapter))->execute($fkColumn, $parentId);
    }

    private function syncPivotRelation(
        object                $resourceModel,
        ResourceModelMetadata $metadata,
        RelationMetadata      $relation,
        mixed                 $value,
        bool                  $isInsert,
        DatabaseAdapterInterface $adapter,
    ): void {
        // A SyncPivotOnly relation without a pivot table cannot be written. The
        // hand-built SQL used to interpolate the null into an empty identifier
        // and fail at the driver with an unreadable message; fail here instead,
        // naming the relation that is misdeclared.
        $pivotTable = $relation->pivotTable;
        if ($pivotTable === null) {
            throw new \LogicException(sprintf(
                'Relation %s::$%s declares RelationWritePolicy::SyncPivotOnly but no pivotTable.',
                $resourceModel::class,
                $relation->propertyName,
            ));
        }

        $parentId = $this->propertyValue($resourceModel, $this->requirePrimaryKey($metadata));
        (new DeleteQuery($pivotTable, $adapter))->execute($relation->foreignKey, $parentId);

        if ($value === null || (!$isInsert && $value === [])) {
            return;
        }

        $rows = [];
        foreach ($this->iterableValue($value) as $item) {
            $rows[] = [
                $relation->foreignKey => $parentId,
                $relation->relatedKey => $this->extractRelatedIdentifier($item, $relation),
            ];
        }
        if ($rows === []) {
            return;
        }

        // One multi-row INSERT per chunk instead of one round-trip per related
        // item (M INSERTs → ceil(M / CHUNK)). Chunked so a pathologically large
        // pivot set stays under the driver's bound-parameter limit.
        $insert = new InsertQuery($pivotTable, $adapter);
        foreach (array_chunk($rows, self::PIVOT_INSERT_CHUNK) as $chunk) {
            $insert->executeBatch($chunk);
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

    /**
     * @param class-string $resourceModelClass
     */
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

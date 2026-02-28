<?php

declare(strict_types=1);

namespace Semitexa\Orm\Repository;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Attribute\TenantScoped;
use Semitexa\Orm\Contract\DomainMappable;
use Semitexa\Orm\Contract\FilterableResourceInterface;
use Semitexa\Orm\Hydration\Hydrator;
use Semitexa\Orm\Hydration\StreamingHydrator;
use Semitexa\Orm\Query\DeleteQuery;
use Semitexa\Orm\Query\InsertQuery;
use Semitexa\Orm\Query\SelectQuery;
use Semitexa\Orm\Query\UpdateQuery;
use Semitexa\Core\Tenant\Scope\TenantScopeInterface;
use Semitexa\Core\Tenant\DefaultTenantContext;
use Semitexa\Orm\Tenant\ColumnInjectingScope;
use Semitexa\Tenancy\Context\CoroutineContextStore;

abstract class AbstractRepository implements RepositoryInterface
{
    private string $tableName;
    /** DB column name for the PK */
    private string $pkColumn;
    /** PHP property name for the PK (may differ from $pkColumn) */
    private string $pkPropertyName;
    private ?string $mapToClass;
    private StreamingHydrator $streamingHydrator;
    private Hydrator $hydrator;
    private ?DatabaseAdapterInterface $transactionConnection = null;
    private ?TenantScopeInterface $tenantScope = null;

    /**
     * Subclass must define the Resource class via this method.
     *
     * @return class-string
     */
    abstract protected function getResourceClass(): string;

    public function __construct(
        private readonly DatabaseAdapterInterface $adapter,
        ?StreamingHydrator $streamingHydrator = null,
    ) {
        $this->streamingHydrator = $streamingHydrator ?? new StreamingHydrator();
        $this->hydrator = $this->streamingHydrator->getHydrator();
        $this->resolveMetadata();
    }

    public function findById(int|string $id): ?object
    {
        return $this->select()
            ->where($this->pkColumn, '=', $id)
            ->fetchOne();
    }

    public function findAll(int $limit = 1000): array
    {
        return $this->select()->limit($limit)->fetchAll();
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function findOneBy(array $criteria): ?object
    {
        $query = $this->select();
        $this->applyCriteriaToQuery($query, $criteria);
        return $query->fetchOne();
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function findBy(array $criteria): array
    {
        $query = $this->select();
        $this->applyCriteriaToQuery($query, $criteria);
        return $query->fetchAll();
    }

    public function find(object $resource): array
    {
        $resourceClass = $this->getResourceClass();
        if (!$resource instanceof $resourceClass) {
            throw new \InvalidArgumentException(
                'Repository ' . static::class . ' accepts only ' . $resourceClass . ', got ' . $resource::class . '.'
            );
        }
        if (!$resource instanceof FilterableResourceInterface) {
            throw new \InvalidArgumentException(
                'Resource must implement ' . FilterableResourceInterface::class . ' to use find(object).'
            );
        }
        $query = $this->select();
        $this->applyCriteriaToQuery($query, $resource->getFilterCriteria());
        $relationCriteria = $resource->getRelationFilterCriteria();
        if ($relationCriteria !== []) {
            $query->applyRelationCriteria($relationCriteria);
        }
        return $query->fetchAll();
    }

    public function findOne(object $resource): ?object
    {
        $resourceClass = $this->getResourceClass();
        if (!$resource instanceof $resourceClass) {
            throw new \InvalidArgumentException(
                'Repository ' . static::class . ' accepts only ' . $resourceClass . ', got ' . $resource::class . '.'
            );
        }
        if (!$resource instanceof FilterableResourceInterface) {
            throw new \InvalidArgumentException(
                'Resource must implement ' . FilterableResourceInterface::class . ' to use findOne(object).'
            );
        }
        $query = $this->select();
        $this->applyCriteriaToQuery($query, $resource->getFilterCriteria());
        $relationCriteria = $resource->getRelationFilterCriteria();
        if ($relationCriteria !== []) {
            $query->applyRelationCriteria($relationCriteria);
        }
        return $query->fetchOne();
    }

    /**
     * Apply main-table criteria to a SelectQuery (null => whereNull, array => whereIn, scalar => where =).
     *
     * @param array<string, mixed> $criteria
     */
    private function applyCriteriaToQuery(SelectQuery $query, array $criteria): void
    {
        foreach ($criteria as $column => $value) {
            if ($value === null) {
                $query->whereNull($column);
            } elseif (is_array($value)) {
                $query->whereIn($column, $value);
            } else {
                $query->where($column, '=', $value);
            }
        }
    }

    /**
     * Save (INSERT or UPDATE) an entity.
     *
     * Accepts:
     * - Resource object → persisted directly
     * - Domain object (matching mapTo class) → converted via Resource::fromDomain()
     */
    public function save(object $entity): void
    {
        $resource = $this->toResource($entity);
        $activeAdapter = $this->getActiveAdapter();

        $this->beforeSave($resource);

        $data = $this->hydrator->dehydrate($resource);
        $this->injectTenantColumns($data);
        $pkValue = $data[$this->pkColumn] ?? null;

        if ($pkValue === null) {
            // Auto-increment INSERT — exclude PK so the DB assigns it
            unset($data[$this->pkColumn]);
            $insertId = (new InsertQuery($this->tableName, $activeAdapter))->execute($data);

            // Set the generated PK back on the resource
            if ($insertId !== '' && $insertId !== '0') {
                $ref = new \ReflectionProperty($resource, $this->pkPropertyName);
                $type = $ref->getType();
                $pkTyped = ($type instanceof \ReflectionNamedType && $type->getName() === 'int')
                    ? (int) $insertId
                    : $insertId;
                $ref->setValue($resource, $pkTyped);

                // Also set on original entity if it's a Domain object with a setter
                if ($entity !== $resource) {
                    $this->setPkOnDomain($entity, $pkTyped);
                }
            }
        } else {
            // PK is set — use INSERT … ON DUPLICATE KEY UPDATE so both new records
            // (pre-assigned UUID / explicit ID) and existing records are handled
            // with a single query and no racy SELECT-then-INSERT.
            (new InsertQuery($this->tableName, $activeAdapter))->execute($data, upsert: true);
        }

        // Cascade save for "touched" relation fields (MVP)
        $cascadeSaver = new CascadeSaver($activeAdapter, $this->hydrator);
        $cascadeSaver->saveTouchedRelations($resource);

        $this->afterSave($resource);
    }

    public function delete(object $entity): void
    {
        $resource      = $this->toResource($entity);
        $activeAdapter = $this->getActiveAdapter();
        $pkValue       = (new \ReflectionProperty($resource, $this->pkPropertyName))->getValue($resource);

        $this->beforeDelete($resource);

        // Soft delete: if the resource uses SoftDeletes trait, set deleted_at instead of DELETE.
        if (method_exists($resource, 'markAsDeleted')) {
            $resource->markAsDeleted();
            $data = $this->hydrator->dehydrate($resource);
            (new UpdateQuery($this->tableName, $activeAdapter))->execute($data, $this->pkColumn);
            $this->afterDelete($resource);
            return;
        }

        // Hard delete: cascade-delete children / pivot rows, then remove the main record.
        (new CascadeDeleter($activeAdapter))->deleteRelations($resource);
        (new DeleteQuery($this->tableName, $activeAdapter))->execute($this->pkColumn, $pkValue);

        $this->afterDelete($resource);
    }

    /**
     * Restore a soft-deleted entity (clears deleted_at and saves).
     * Only works for resources using SoftDeletes trait.
     */
    public function restore(object $entity): void
    {
        $resource = $this->toResource($entity);
        if (!method_exists($resource, 'restore')) {
            throw new \LogicException(
                $this->getResourceClass() . ' does not use SoftDeletes trait.'
            );
        }
        $resource->restore();
        $this->save($resource);
    }

    /**
     * Called before INSERT/UPDATE, after converting entity to resource.
     * Override to add custom logic; always call parent::beforeSave() when overriding.
     */
    protected function beforeSave(object $resource): void
    {
        // Auto-generate UUID if the resource uses HasUuid trait and uuid is empty
        if (method_exists($resource, 'ensureUuid')) {
            $resource->ensureUuid();
        }

        // Auto-fill timestamps if the resource uses HasTimestamps trait
        if (method_exists($resource, 'touchTimestamps')) {
            $resource->touchTimestamps();
        }
    }

    /**
     * Called after INSERT/UPDATE and cascade save.
     * Override for audit trail, cache invalidation, event dispatch, etc.
     */
    protected function afterSave(object $resource): void {}

    /**
     * Called before cascade delete and the main DELETE query.
     * Override for soft-delete guards, audit trail, etc.
     */
    protected function beforeDelete(object $resource): void {}

    /**
     * Called after the DELETE query completes.
     * Override for cache invalidation, event dispatch, etc.
     */
    protected function afterDelete(object $resource): void {}

    /**
     * Return a clone of this repository that uses the given connection for all queries.
     * Used by TransactionManager to bind operations to a single transactional connection.
     *
     * @return static
     */
    public function useConnection(DatabaseAdapterInterface $connection): static
    {
        $clone = clone $this;
        $clone->transactionConnection = $connection;
        return $clone;
    }

    /**
     * Internal query builder for SELECT — available to subclasses.
     * Automatically appends `WHERE deleted_at IS NULL` for resources using SoftDeletes trait.
     * Automatically applies tenant scope if the resource is marked with #[TenantScoped].
     */
    protected function select(): SelectQuery
    {
        $query = new SelectQuery(
            $this->tableName,
            $this->getResourceClass(),
            $this->getActiveAdapter(),
            $this->streamingHydrator,
        );

        if ($this->usesSoftDeletes()) {
            $query->whereNull('deleted_at');
        }

        $this->applyTenantScope($query);

        return $query;
    }

    /**
     * Query builder that includes soft-deleted records.
     * Use when you need to work with deleted records (restore, audit, etc.).
     * Still applies tenant scope.
     */
    protected function selectWithTrashed(): SelectQuery
    {
        $query = new SelectQuery(
            $this->tableName,
            $this->getResourceClass(),
            $this->getActiveAdapter(),
            $this->streamingHydrator,
        );

        $this->applyTenantScope($query);

        return $query;
    }

    /**
     * Set a custom tenant scope strategy.
     * If not set, uses NullTenantScope from core.
     */
    public function setTenantScope(?TenantScopeInterface $scope): void
    {
        $this->tenantScope = $scope;
    }

    /**
     * Inject tenant identifier columns into dehydrated row data before INSERT/UPDATE.
     * Only runs when the active scope implements ColumnInjectingScope (same-storage isolation).
     *
     * @param array<string, mixed> $data Modified in place
     */
    private function injectTenantColumns(array &$data): void
    {
        $scope = $this->tenantScope;

        if (!$scope instanceof ColumnInjectingScope) {
            return;
        }

        $resourceClass = $this->getResourceClass();
        $ref = new \ReflectionClass($resourceClass);

        if ($ref->getAttributes(TenantScoped::class) === []) {
            return;
        }

        $context = CoroutineContextStore::get() ?? DefaultTenantContext::getInstance();
        $scope->injectColumns($data, $context);
    }

    /**
     * Apply tenant scope to the query if the resource is tenant-scoped.
     */
    private function applyTenantScope(SelectQuery $query): void
    {
        $resourceClass = $this->getResourceClass();
        $ref = new \ReflectionClass($resourceClass);
        
        $tenantAttr = $ref->getAttributes(TenantScoped::class);
        if ($tenantAttr === []) {
            return;
        }

        /** @var TenantScoped $attr */
        $attr = $tenantAttr[0]->newInstance();

        $scope = $this->tenantScope;
        
        if ($scope === null) {
            return;
        }

        $context = CoroutineContextStore::get() ?? DefaultTenantContext::getInstance();
        
        $scope->apply($query, $context);
    }

    /**
     * Whether the resource class uses the SoftDeletes trait.
     */
    private function usesSoftDeletes(): bool
    {
        return method_exists($this->getResourceClass(), 'markAsDeleted');
    }

    /**
     * Raw SQL with automatic hydration to Domain objects.
     *
     * @param array<string, mixed> $params
     * @return object[]
     */
    protected function raw(string $sql, array $params = []): array
    {
        $result = $this->getActiveAdapter()->execute($sql, $params);

        return $this->streamingHydrator->hydrateAllToDomain($result->rows, $this->getResourceClass());
    }

    /**
     * Get the database adapter (for subclasses that need direct access).
     */
    protected function getAdapter(): DatabaseAdapterInterface
    {
        return $this->getActiveAdapter();
    }

    /**
     * Returns the transaction-bound connection if set, otherwise the default adapter.
     */
    private function getActiveAdapter(): DatabaseAdapterInterface
    {
        return $this->transactionConnection ?? $this->adapter;
    }

    /**
     * Get the table name for this repository.
     */
    protected function getTableName(): string
    {
        return $this->tableName;
    }

    /**
     * Get the primary key column name.
     */
    protected function getPkColumn(): string
    {
        return $this->pkColumn;
    }

    /**
     * Convert entity to Resource. If it's already a Resource, return as-is.
     * If it's a Domain object, use Resource::fromDomain().
     */
    private function toResource(object $entity): object
    {
        $resourceClass = $this->getResourceClass();

        if ($entity instanceof $resourceClass) {
            return $entity;
        }

        // Domain → Resource via fromDomain()
        if (is_subclass_of($resourceClass, DomainMappable::class)
            || in_array(DomainMappable::class, class_implements($resourceClass) ?: [], true)
        ) {
            return $resourceClass::fromDomain($entity);
        }

        throw new \InvalidArgumentException(
            "Cannot convert " . $entity::class . " to {$resourceClass}. "
            . "Resource must implement DomainMappable for domain-to-resource conversion."
        );
    }

    /**
     * Try to set the generated PK on a Domain entity via setter.
     */
    private function setPkOnDomain(object $entity, mixed $pkValue): void
    {
        // Try setId() method (convention)
        $setter = 'set' . ucfirst($this->pkPropertyName);
        if (method_exists($entity, $setter)) {
            $entity->{$setter}($pkValue);
            return;
        }

        // Try direct property assignment
        $ref = new \ReflectionClass($entity);
        if ($ref->hasProperty($this->pkPropertyName)) {
            $prop = $ref->getProperty($this->pkPropertyName);
            if ($prop->isPublic()) {
                $prop->setValue($entity, $pkValue);
            }
        }
    }

    /**
     * Read FromTable and PrimaryKey from the Resource class attributes.
     */
    private function resolveMetadata(): void
    {
        $resourceClass = $this->getResourceClass();
        $ref = new \ReflectionClass($resourceClass);

        // Table name from #[FromTable]
        $fromTableAttrs = $ref->getAttributes(FromTable::class);
        if ($fromTableAttrs === []) {
            throw new \RuntimeException("Resource class {$resourceClass} must have #[FromTable] attribute.");
        }
        /** @var FromTable $fromTable */
        $fromTable = $fromTableAttrs[0]->newInstance();
        $this->tableName = $fromTable->name;
        $this->mapToClass = $fromTable->mapTo;

        // Primary key column from #[PrimaryKey]
        $this->pkColumn = 'id'; // default DB column name
        $this->pkPropertyName = 'id'; // default PHP property name
        foreach ($ref->getProperties() as $prop) {
            if ($prop->getAttributes(PrimaryKey::class) !== []) {
                $this->pkPropertyName = $prop->getName();
                $colAttrs = $prop->getAttributes(Column::class);
                if ($colAttrs !== []) {
                    /** @var Column $col */
                    $col = $colAttrs[0]->newInstance();
                    $this->pkColumn = $col->name ?? $prop->getName();
                } else {
                    $this->pkColumn = $prop->getName();
                }
                break;
            }
        }
    }
}

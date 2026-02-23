<?php

declare(strict_types=1);

namespace Semitexa\Orm\Repository;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Contract\DomainMappable;
use Semitexa\Orm\Hydration\Hydrator;
use Semitexa\Orm\Hydration\StreamingHydrator;
use Semitexa\Orm\Query\DeleteQuery;
use Semitexa\Orm\Query\InsertQuery;
use Semitexa\Orm\Query\SelectQuery;
use Semitexa\Orm\Query\UpdateQuery;

abstract class AbstractRepository implements RepositoryInterface
{
    private string $tableName;
    private string $pkColumn;
    private ?string $mapToClass;
    private StreamingHydrator $streamingHydrator;
    private Hydrator $hydrator;
    private ?DatabaseAdapterInterface $transactionConnection = null;

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

    public function findAll(): array
    {
        return $this->select()->fetchAll();
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function findBy(array $criteria): array
    {
        $query = $this->select();

        foreach ($criteria as $column => $value) {
            if ($value === null) {
                $query->whereNull($column);
            } elseif (is_array($value)) {
                $query->whereIn($column, $value);
            } else {
                $query->where($column, '=', $value);
            }
        }

        return $query->fetchAll();
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
        $data = $this->hydrator->dehydrate($resource);

        $pkValue = $data[$this->pkColumn] ?? null;

        if ($pkValue === null || $pkValue === 0 || $pkValue === '') {
            // INSERT — remove PK if it's auto-increment with no value
            unset($data[$this->pkColumn]);
            $insertId = (new InsertQuery($this->tableName, $activeAdapter))->execute($data);

            // Set the generated PK back on the resource
            if ($insertId !== '' && $insertId !== '0') {
                $ref = new \ReflectionProperty($resource, $this->pkColumn);
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
            // UPDATE
            (new UpdateQuery($this->tableName, $activeAdapter))->execute($data, $this->pkColumn);
        }

        // Cascade save for "touched" relation fields (MVP)
        $cascadeSaver = new CascadeSaver($activeAdapter, $this->hydrator);
        $cascadeSaver->saveTouchedRelations($resource);
    }

    public function delete(object $entity): void
    {
        $resource = $this->toResource($entity);
        $ref = new \ReflectionProperty($resource, $this->pkColumn);
        $pkValue = $ref->getValue($resource);

        (new DeleteQuery($this->tableName, $this->getActiveAdapter()))->execute($this->pkColumn, $pkValue);
    }

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
     */
    protected function select(): SelectQuery
    {
        return new SelectQuery(
            $this->tableName,
            $this->getResourceClass(),
            $this->getActiveAdapter(),
            $this->streamingHydrator,
        );
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
        $setter = 'set' . ucfirst($this->pkColumn);
        if (method_exists($entity, $setter)) {
            $entity->{$setter}($pkValue);
            return;
        }

        // Try direct property assignment
        $ref = new \ReflectionClass($entity);
        if ($ref->hasProperty($this->pkColumn)) {
            $prop = $ref->getProperty($this->pkColumn);
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
        $this->pkColumn = 'id'; // default
        foreach ($ref->getProperties() as $prop) {
            $pkAttrs = $prop->getAttributes(PrimaryKey::class);
            if ($pkAttrs !== []) {
                $this->pkColumn = $prop->getName();
                break;
            }
        }
    }
}

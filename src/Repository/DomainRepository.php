<?php

declare(strict_types=1);

namespace Semitexa\Orm\Repository;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Exception\InvalidResourceModelException;
use Semitexa\Orm\Hydration\ResourceModelHydrator;
use Semitexa\Orm\Hydration\ResourceModelRelationLoader;
use Semitexa\Orm\Mapping\MapperRegistry;
use Semitexa\Orm\Metadata\ColumnRef;
use Semitexa\Orm\Metadata\RelationRef;
use Semitexa\Orm\Metadata\ResourceModelMetadata;
use Semitexa\Orm\Metadata\ResourceModelMetadataRegistry;
use Semitexa\Orm\Persistence\AggregateWriteEngine;
use Semitexa\Orm\Query\Direction;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Query\SystemScopeToken;
use Semitexa\Orm\Query\ResourceModelQuery;

/**
 * Domain-facing repository: crosses the ResourceModel ↔ DomainModel boundary.
 *
 * Build queries with {@see query()} (typed, fluent) or use the convenience
 * {@see findById}, {@see findBy}, {@see count}, {@see paginate} helpers.
 * All read methods respect the tenant scope set by {@see forTenant} /
 * {@see withoutTenantScope}.
 */
final class DomainRepository
{
    private readonly ResourceModelHydrator $hydrator;
    private readonly ResourceModelRelationLoader $relationLoader;
    private readonly AggregateWriteEngine $writeEngine;

    private mixed $tenantValue = null;
    private ?SystemScopeToken $systemScopeToken = null;

    /**
     * @param class-string $resourceModelClass
     * @param class-string $domainModelClass
     */
    public function __construct(
        private readonly string                         $resourceModelClass,
        private readonly string                         $domainModelClass,
        private readonly DatabaseAdapterInterface       $adapter,
        private readonly MapperRegistry                 $mapperRegistry,
        ?ResourceModelHydrator                          $hydrator = null,
        ?ResourceModelRelationLoader                    $relationLoader = null,
        private readonly ?ResourceModelMetadataRegistry $metadataRegistry = null,
        ?AggregateWriteEngine                           $writeEngine = null,
    ) {
        $this->hydrator = $hydrator ?? new ResourceModelHydrator(metadataRegistry: $metadataRegistry);
        $this->relationLoader = $relationLoader ?? new ResourceModelRelationLoader(
            $adapter,
            $this->hydrator,
            $metadataRegistry,
        );
        $this->writeEngine = $writeEngine ?? new AggregateWriteEngine(
            $adapter,
            $this->hydrator,
            $metadataRegistry,
        );
    }

    public function forTenant(mixed $tenantValue): self
    {
        $clone = clone $this;
        $clone->tenantValue = $tenantValue;
        $clone->systemScopeToken = null;

        return $clone;
    }

    public function withoutTenantScope(SystemScopeToken $token): self
    {
        $clone = clone $this;
        $clone->systemScopeToken = $token;
        $clone->tenantValue = null;

        return $clone;
    }

    public function query(): ResourceModelQuery
    {
        $query = new ResourceModelQuery(
            $this->resourceModelClass,
            $this->adapter,
            $this->hydrator,
            $this->relationLoader,
            $this->metadataRegistry,
        );

        if ($this->systemScopeToken !== null) {
            $query->withoutTenantScope($this->systemScopeToken);
        } elseif ($this->tenantValue !== null) {
            $query->forTenant($this->tenantValue);
        }

        return $query;
    }

    public function findById(int|string $id): ?object
    {
        return $this->query()
            ->where(ColumnRef::for($this->resourceModelClass, $this->requirePrimaryKey()), Operator::Equals, $id)
            ->fetchOneAs($this->domainModelClass, $this->mapperRegistry);
    }

    /**
     * @throws \RuntimeException when the id is not found
     */
    public function findByIdOrFail(int|string $id): object
    {
        $entity = $this->findById($id);
        if ($entity === null) {
            throw new \RuntimeException(sprintf(
                'No %s found with id "%s".',
                $this->domainModelClass,
                (string) $id,
            ));
        }

        return $entity;
    }

    /**
     * Fetch every row (subject to $limit). Pass null for unbounded fetches
     * — use with care on large tables.
     *
     * @return list<object>
     */
    public function findAll(?int $limit = 1000): array
    {
        $query = $this->query();
        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->fetchAllAs($this->domainModelClass, $this->mapperRegistry);
    }

    /**
     * @param array<string, mixed> $criteria property name → value (null → IS NULL)
     * @param list<RelationRef> $relations
     * @return list<object>
     */
    public function findBy(array $criteria, array $relations = [], ?int $limit = null): array
    {
        $query = $this->applyCriteria($this->query(), $criteria, $relations);

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->fetchAllAs($this->domainModelClass, $this->mapperRegistry);
    }

    /**
     * @param array<string, mixed> $criteria
     * @param list<RelationRef> $relations
     */
    public function findOneBy(array $criteria, array $relations = []): ?object
    {
        return $this->applyCriteria($this->query(), $criteria, $relations)
            ->fetchOneAs($this->domainModelClass, $this->mapperRegistry);
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function count(array $criteria = []): int
    {
        return $this->applyCriteria($this->query(), $criteria)->count();
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function exists(array $criteria = []): bool
    {
        return $this->applyCriteria($this->query(), $criteria)->exists();
    }

    /**
     * Paginate and return domain models.
     *
     * @param array<string, mixed> $criteria
     * @param list<RelationRef> $relations
     */
    public function paginate(int $page, int $perPage, array $criteria = [], array $relations = []): PaginatedResult
    {
        $query = $this->applyCriteria($this->query(), $criteria, $relations);

        return $query->paginateAs($page, $perPage, $this->domainModelClass, $this->mapperRegistry);
    }

    public function insert(object $domainModel): object
    {
        return $this->writeEngine->insert($domainModel, $this->resourceModelClass, $this->mapperRegistry);
    }

    public function update(object $domainModel): object
    {
        return $this->writeEngine->update($domainModel, $this->resourceModelClass, $this->mapperRegistry);
    }

    public function delete(object $domainModel): void
    {
        $this->writeEngine->delete($domainModel, $this->resourceModelClass, $this->mapperRegistry);
    }

    /**
     * @deprecated Use $repository->query()->orderBy($column, $direction) directly — clearer fluent chain.
     */
    public function orderBy(ResourceModelQuery $query, ColumnRef $column, Direction $direction): ResourceModelQuery
    {
        return $query->orderBy($column, $direction);
    }

    /**
     * @param array<string, mixed> $criteria
     * @param list<RelationRef> $relations
     */
    private function applyCriteria(ResourceModelQuery $query, array $criteria, array $relations = []): ResourceModelQuery
    {
        foreach ($criteria as $propertyName => $value) {
            $column = ColumnRef::for($this->resourceModelClass, $propertyName);
            if ($value === null) {
                $query->whereNull($column);
                continue;
            }
            if (is_array($value) && array_is_list($value)) {
                /** @var list<mixed> $value */
                $query->whereIn($column, $value);
                continue;
            }

            $query->where($column, Operator::Equals, $value);
        }

        foreach ($relations as $relation) {
            $query->withRelation($relation);
        }

        return $query;
    }

    private function metadata(): ResourceModelMetadata
    {
        return ($this->metadataRegistry ?? ResourceModelMetadataRegistry::default())->for($this->resourceModelClass);
    }

    private function requirePrimaryKey(): string
    {
        $primaryKey = $this->metadata()->primaryKeyProperty;
        if ($primaryKey === null) {
            throw new InvalidResourceModelException(sprintf(
                'Resource model %s has no primary key metadata.',
                $this->resourceModelClass,
            ));
        }

        return $primaryKey;
    }
}

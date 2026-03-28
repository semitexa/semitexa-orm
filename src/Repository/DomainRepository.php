<?php

declare(strict_types=1);

namespace Semitexa\Orm\Repository;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Hydration\TableModelHydrator;
use Semitexa\Orm\Hydration\TableModelRelationLoader;
use Semitexa\Orm\Mapping\MapperRegistry;
use Semitexa\Orm\Metadata\ColumnRef;
use Semitexa\Orm\Metadata\RelationRef;
use Semitexa\Orm\Metadata\TableModelMetadataRegistry;
use Semitexa\Orm\Persistence\AggregateWriteEngine;
use Semitexa\Orm\Query\Direction;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Query\SystemScopeToken;
use Semitexa\Orm\Query\TableModelQuery;

final class DomainRepository
{
    private readonly TableModelHydrator $hydrator;
    private readonly TableModelRelationLoader $relationLoader;
    private readonly AggregateWriteEngine $writeEngine;

    private mixed $tenantValue = null;
    private ?SystemScopeToken $systemScopeToken = null;

    public function __construct(
        private readonly string $tableModelClass,
        private readonly string $domainModelClass,
        private readonly DatabaseAdapterInterface $adapter,
        private readonly MapperRegistry $mapperRegistry,
        ?TableModelHydrator $hydrator = null,
        ?TableModelRelationLoader $relationLoader = null,
        private readonly ?TableModelMetadataRegistry $metadataRegistry = null,
        ?AggregateWriteEngine $writeEngine = null,
    ) {
        $this->hydrator = $hydrator ?? new TableModelHydrator(metadataRegistry: $metadataRegistry);
        $this->relationLoader = $relationLoader ?? new TableModelRelationLoader(
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

    public function query(): TableModelQuery
    {
        $query = new TableModelQuery(
            $this->tableModelClass,
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
        $metadata = ($this->metadataRegistry ?? TableModelMetadataRegistry::default())->for($this->tableModelClass);
        $primaryKey = $metadata->primaryKeyProperty
            ?? throw new \LogicException(sprintf('Table model %s has no primary key metadata.', $this->tableModelClass));

        return $this->query()
            ->where(ColumnRef::for($this->tableModelClass, $primaryKey), Operator::Equals, $id)
            ->fetchOneAs($this->domainModelClass, $this->mapperRegistry);
    }

    /**
     * @return list<object>
     */
    public function findAll(int $limit = 1000): array
    {
        return $this->query()
            ->limit($limit)
            ->fetchAllAs($this->domainModelClass, $this->mapperRegistry);
    }

    /**
     * @param array<string, mixed> $criteria
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
     */
    public function findOneBy(array $criteria, array $relations = []): ?object
    {
        return $this->applyCriteria($this->query(), $criteria, $relations)
            ->fetchOneAs($this->domainModelClass, $this->mapperRegistry);
    }

    public function insert(object $domainModel): object
    {
        return $this->writeEngine->insert($domainModel, $this->tableModelClass, $this->mapperRegistry);
    }

    public function update(object $domainModel): object
    {
        return $this->writeEngine->update($domainModel, $this->tableModelClass, $this->mapperRegistry);
    }

    public function delete(object $domainModel): void
    {
        $this->writeEngine->delete($domainModel, $this->tableModelClass, $this->mapperRegistry);
    }

    public function orderBy(TableModelQuery $query, ColumnRef $column, Direction $direction): TableModelQuery
    {
        return $query->orderBy($column, $direction);
    }

    /**
     * @param array<string, mixed> $criteria
     * @param list<RelationRef> $relations
     */
    private function applyCriteria(TableModelQuery $query, array $criteria, array $relations = []): TableModelQuery
    {
        foreach ($criteria as $propertyName => $value) {
            $column = ColumnRef::for($this->tableModelClass, $propertyName);
            if ($value === null) {
                $query->whereNull($column);
                continue;
            }

            $query->where($column, Operator::Equals, $value);
        }

        foreach ($relations as $relation) {
            $query->withRelation($relation);
        }

        return $query;
    }
}

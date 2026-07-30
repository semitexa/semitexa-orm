<?php

declare(strict_types=1);

namespace Semitexa\Orm\Repository;

use Semitexa\Core\Event\EventDispatcherInterface;
use Semitexa\Core\Exception\NotFoundException;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Exception\InvalidResourceModelException;
use Semitexa\Orm\Application\Service\Hydration\ResourceModelHydrator;
use Semitexa\Orm\Application\Service\Hydration\ResourceModelRelationLoader;
use Semitexa\Orm\Application\Service\Mapping\MapperRegistry;
use Semitexa\Orm\Metadata\ColumnRef;
use Semitexa\Orm\Metadata\RelationRef;
use Semitexa\Orm\Metadata\ResourceModelMetadata;
use Semitexa\Orm\Metadata\ResourceModelMetadataRegistry;
use Semitexa\Orm\Application\Service\Persistence\AggregateWriteEngine;
use Semitexa\Orm\Application\Service\Transaction\TransactionManager;
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
        EventDispatcherInterface|\Closure|null          $events = null,
        TransactionManager|\Closure|null                $transactions = null,
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
            $events,
            $transactions,
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
            mapperRegistry: $this->mapperRegistry,
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
            // A typed NotFoundException maps to HTTP 404 (getStatusCode) instead
            // of the generic 500 a bare RuntimeException produced. Still a
            // RuntimeException (DomainException extends it), so existing broad
            // catches keep working.
            throw new NotFoundException($this->domainModelClass, $id);
        }

        return $entity;
    }

    /**
     * The bound applied when a caller does not choose one.
     *
     * Keeps an unsuspecting caller from loading a whole table into memory. The
     * bound is deliberate; what was wrong was applying it in silence.
     */
    public const DEFAULT_LIMIT = 1000;

    /**
     * Distinguishes "did not ask for a limit" from "asked for exactly this
     * many".
     *
     * The distinction decides who gets warned. A caller who passes a limit
     * knows one exists and expects to receive it — a paginated screen asking
     * for 50 and getting 50 is working correctly, and warning about it would
     * be noise that eventually gets the warning ignored. The caller who never
     * passed one is the one who does not know their results were cut.
     *
     * Any negative value reads as unspecified, so an explicit -1 behaves the
     * same rather than producing a nonsensical query.
     */
    private const LIMIT_UNSPECIFIED = -1;

    /**
     * Fetch every row (subject to $limit). Pass null for unbounded fetches
     * — use with care on large tables.
     *
     * @return list<object>
     */
    public function findAll(?int $limit = self::LIMIT_UNSPECIFIED): array
    {
        return $this->fetchBounded($this->query(), $limit, 'findAll');
    }

    /**
     * Fetch rows matching $criteria, bounded to $limit (default 1000, matching
     * {@see findAll()}). Pass null for an unbounded fetch — use with care on
     * large tables. The old null default meant a caller omitting $limit loaded
     * the entire matching set into memory; the bounded default prevents that
     * accidental whole-table load while keeping unbounded an explicit opt-in.
     *
     * @param array<string, mixed> $criteria property name → value (null → IS NULL)
     * @param list<RelationRef> $relations
     * @return list<object>
     */
    public function findBy(array $criteria, array $relations = [], ?int $limit = self::LIMIT_UNSPECIFIED): array
    {
        return $this->fetchBounded(
            $this->applyCriteria($this->query(), $criteria, $relations),
            $limit,
            'findBy',
        );
    }

    /**
     * Run a bounded fetch and say so when the bound was probably reached.
     *
     * A result whose size exactly equals a limit the caller never asked for is
     * almost certainly a truncated one. It cannot be told apart from a complete
     * result by looking at it, which is how a silently cut list becomes wrong
     * data on a screen rather than an error anybody sees.
     *
     * Detecting it costs a comparison. The one imprecision — a set that happens
     * to hold exactly DEFAULT_LIMIT rows — reports a truncation that did not
     * occur, and that is the right way round: the message says how to check and
     * how to opt out, whereas silence offers nothing.
     *
     * @return list<object>
     */
    private function fetchBounded(ResourceModelQuery $query, ?int $limit, string $method): array
    {
        // The sentinel exactly, not "any negative". Treating every negative as
        // omission meant findAll(-2) silently became the default bound and could
        // then log that no limit was passed — a warning stating something the
        // caller can see is untrue, which is how a channel stops being read.
        // Any other negative falls through to ResourceModelQuery::limit(), which
        // already rejects it by name.
        $unspecified = $limit === self::LIMIT_UNSPECIFIED;
        $effective = $unspecified ? self::DEFAULT_LIMIT : $limit;

        if ($effective !== null) {
            $query->limit($effective);
        }

        /** @var list<object> $rows */
        $rows = $query->fetchAllAs($this->domainModelClass, $this->mapperRegistry);

        if ($unspecified && count($rows) === self::DEFAULT_LIMIT) {
            StaticLoggerBridge::warning('orm', 'Result reached the default limit and was probably truncated', [
                'model' => $this->domainModelClass,
                'method' => $method,
                'limit' => self::DEFAULT_LIMIT,
                'note' => 'no limit was passed, so this bound is the default. '
                    . 'Pass an explicit limit to accept it silently, or null for an unbounded fetch.',
            ]);
        }

        return $rows;
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
     * @return PaginatedResult<object>
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

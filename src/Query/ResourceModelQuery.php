<?php

declare(strict_types=1);

namespace Semitexa\Orm\Query;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Hydration\ResourceModelHydrator;
use Semitexa\Orm\Hydration\ResourceModelRelationLoader;
use Semitexa\Orm\Hydration\TypeCaster;
use Semitexa\Orm\Mapping\MapperRegistry;
use Semitexa\Orm\Metadata\ColumnRef;
use Semitexa\Orm\Metadata\ColumnMetadata;
use Semitexa\Orm\Metadata\RelationRef;
use Semitexa\Orm\Metadata\ResourceModelMetadata;
use Semitexa\Orm\Metadata\ResourceModelMetadataRegistry;
use Semitexa\Orm\Repository\PaginatedResult;
use Semitexa\Orm\Schema\ColumnDefinition;

/**
 * Typed, composable query builder for a ResourceModel.
 *
 * Uses {@see ColumnRef} / {@see RelationRef} for compile-time-ish safety:
 * columns/relations from a different ResourceModel are rejected, and all
 * values are type-cast through {@see TypeCaster} before binding.
 *
 * All where*() methods return $this (fluent chain) and join conditions
 * with the implicit tenant-scope / soft-delete gates. Use {@see orWhere}
 * to build OR branches.
 *
 * The builder is designed to be cheap to clone — callers that need to
 * branch can clone ResourceModelQuery and adjust limit/offset on the clone.
 *
 * @phpstan-type SqlCondition array{connector: 'AND'|'OR', sql: string}
 * @phpstan-type WhereComparison array{kind: 'comparison', connector: 'AND'|'OR', column: ColumnRef, operator: Operator, param: string, value: mixed}
 * @phpstan-type WhereNull array{kind: 'null', connector: 'AND'|'OR', column: ColumnRef, negated: bool}
 * @phpstan-type WhereIn array{kind: 'in', connector: 'AND'|'OR', column: ColumnRef, negated: bool, params: list<string>, values: list<mixed>}
 * @phpstan-type WhereBetween array{kind: 'between', connector: 'AND'|'OR', column: ColumnRef, fromParam: string, toParam: string, from: mixed, to: mixed}
 * @phpstan-type WhereRaw array{kind: 'raw', connector: 'AND'|'OR', sql: string, params: array<string, mixed>}
 * @phpstan-type WhereClause WhereComparison|WhereNull|WhereIn|WhereBetween|WhereRaw
 */
final class ResourceModelQuery
{
    /** @var list<WhereClause> */
    private array $wheres = [];

    /** @var list<RelationRef> */
    private array $relations = [];

    /** @var list<array{column: ColumnRef, direction: Direction}> */
    private array $orderBys = [];

    private ?int $limitValue = null;
    private ?int $offsetValue = null;
    private mixed $tenantValue = null;
    private bool $includeSoftDeleted = false;
    private bool $onlySoftDeleted = false;
    private bool $skipTenantScope = false;
    private int $paramCounter = 0;

    /**
     * @param class-string $resourceModelClass
     */
    public function __construct(
        private readonly string                         $resourceModelClass,
        private readonly DatabaseAdapterInterface       $adapter,
        private readonly ResourceModelHydrator          $hydrator,
        private readonly ResourceModelRelationLoader    $relationLoader,
        private readonly ?ResourceModelMetadataRegistry $metadataRegistry = null,
        private readonly ?TypeCaster                    $typeCaster = null,
    ) {}

    // ------------------------------------------------------------------
    // WHERE predicates
    // ------------------------------------------------------------------

    public function where(ColumnRef $column, Operator $operator, mixed $value): self
    {
        $this->assertColumnBelongsToCurrentResourceModel($column);
        $param = $this->nextParam();
        $this->wheres[] = [
            'kind' => 'comparison',
            'connector' => 'AND',
            'column' => $column,
            'operator' => $operator,
            'param' => $param,
            'value' => $this->normalizeComparisonValue($column, $value),
        ];

        return $this;
    }

    public function orWhere(ColumnRef $column, Operator $operator, mixed $value): self
    {
        $this->assertColumnBelongsToCurrentResourceModel($column);
        $param = $this->nextParam();
        $this->wheres[] = [
            'kind' => 'comparison',
            'connector' => 'OR',
            'column' => $column,
            'operator' => $operator,
            'param' => $param,
            'value' => $this->normalizeComparisonValue($column, $value),
        ];

        return $this;
    }

    public function whereNull(ColumnRef $column): self
    {
        return $this->addNull($column, negated: false, connector: 'AND');
    }

    public function whereNotNull(ColumnRef $column): self
    {
        return $this->addNull($column, negated: true, connector: 'AND');
    }

    public function orWhereNull(ColumnRef $column): self
    {
        return $this->addNull($column, negated: false, connector: 'OR');
    }

    public function orWhereNotNull(ColumnRef $column): self
    {
        return $this->addNull($column, negated: true, connector: 'OR');
    }

    /**
     * @param list<mixed> $values
     */
    public function whereIn(ColumnRef $column, array $values): self
    {
        return $this->addIn($column, $values, negated: false, connector: 'AND');
    }

    /**
     * @param list<mixed> $values
     */
    public function whereNotIn(ColumnRef $column, array $values): self
    {
        return $this->addIn($column, $values, negated: true, connector: 'AND');
    }

    public function whereBetween(ColumnRef $column, mixed $from, mixed $to): self
    {
        $this->assertColumnBelongsToCurrentResourceModel($column);
        $fromParam = $this->nextParam();
        $toParam = $this->nextParam();
        $this->wheres[] = [
            'kind' => 'between',
            'connector' => 'AND',
            'column' => $column,
            'fromParam' => $fromParam,
            'toParam' => $toParam,
            'from' => $this->normalizeComparisonValue($column, $from),
            'to' => $this->normalizeComparisonValue($column, $to),
        ];

        return $this;
    }

    public function whereLike(ColumnRef $column, string $pattern): self
    {
        return $this->where($column, Operator::Like, $pattern);
    }

    public function whereNotLike(ColumnRef $column, string $pattern): self
    {
        return $this->where($column, Operator::NotLike, $pattern);
    }

    /**
     * Append a raw SQL fragment to the WHERE clause.
     *
     * Values are bound as named parameters. Use `?` for each binding.
     * Intended as an escape hatch — prefer the typed helpers.
     *
     * @param list<mixed> $bindings
     */
    public function whereRaw(string $sql, array $bindings = []): self
    {
        $placeholderCount = substr_count($sql, '?');
        if ($placeholderCount !== count($bindings)) {
            throw new \InvalidArgumentException(sprintf(
                'whereRaw() expects exactly %d binding(s), got %d.',
                $placeholderCount,
                count($bindings),
            ));
        }

        $params = [];
        foreach ($bindings as $value) {
            $name = $this->nextParam('raw');
            $sql = (string) preg_replace('/\?/', ':' . $name, $sql, 1);
            $params[$name] = $value instanceof \BackedEnum ? $value->value : $value;
        }
        $this->wheres[] = [
            'kind' => 'raw',
            'connector' => 'AND',
            'sql' => $sql,
            'params' => $params,
        ];

        return $this;
    }

    // ------------------------------------------------------------------
    // Relation eager-loading
    // ------------------------------------------------------------------

    public function withRelation(RelationRef $relation): self
    {
        $this->assertRelationBelongsToCurrentResourceModel($relation);
        $this->relations[] = $relation;

        return $this;
    }

    public function orderBy(ColumnRef $column, Direction $direction): self
    {
        $this->assertColumnBelongsToCurrentResourceModel($column);
        $this->orderBys[] = [
            'column' => $column,
            'direction' => $direction,
        ];

        return $this;
    }

    public function limit(int $limit): self
    {
        if ($limit < 0) {
            throw new \InvalidArgumentException('limit() expects a non-negative integer.');
        }
        $this->limitValue = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new \InvalidArgumentException('offset() expects a non-negative integer.');
        }
        $this->offsetValue = $offset;
        return $this;
    }

    public function forTenant(mixed $tenantValue): self
    {
        $this->tenantValue = $tenantValue;

        return $this;
    }

    public function includeSoftDeleted(): self
    {
        $this->includeSoftDeleted = true;
        $this->onlySoftDeleted = false;

        return $this;
    }

    public function onlySoftDeleted(): self
    {
        $this->includeSoftDeleted = false;
        $this->onlySoftDeleted = true;

        return $this;
    }

    public function withoutTenantScope(SystemScopeToken $token): self
    {
        // Token presence itself is the assertion — callers must explicitly issue one.
        unset($token);
        $this->skipTenantScope = true;

        return $this;
    }

    // ------------------------------------------------------------------
    // Execution
    // ------------------------------------------------------------------

    /**
     * @return list<object>
     */
    public function fetchAll(): array
    {
        $result = $this->adapter->execute($this->buildSql(), $this->buildParams());
        $items = array_map(
            fn (array $row): object => $this->hydrator->hydrate($row, $this->resourceModelClass),
            $result->rows,
        );

        if ($items !== [] && $this->relations !== []) {
            $this->relationLoader->loadRelations(
                $items,
                $this->resourceModelClass,
                array_map(static fn (RelationRef $relation): string => $relation->propertyName, $this->relations),
            );
        }

        return array_values($items);
    }

    /**
     * Fetch the first matching row or null.
     *
     * Unlike the previous implementation this does not mutate the builder —
     * a shallow clone with LIMIT 1 is used so the caller can re-run the
     * query afterwards without surprise.
     */
    public function fetchOne(): ?object
    {
        $clone = clone $this;
        $clone->limitValue = 1;
        $clone->offsetValue = $this->offsetValue;
        $items = $clone->fetchAll();

        return $items[0] ?? null;
    }

    /**
     * @return list<object>
     */
    public function fetchAllAs(string $domainModelClass, MapperRegistry $mapperRegistry): array
    {
        return array_map(
            fn (object $resourceModel): object => $mapperRegistry->mapToDomain($resourceModel, $domainModelClass),
            $this->fetchAll(),
        );
    }

    public function fetchOneAs(string $domainModelClass, MapperRegistry $mapperRegistry): ?object
    {
        $item = $this->fetchOne();

        if ($item === null) {
            return null;
        }

        return $mapperRegistry->mapToDomain($item, $domainModelClass);
    }

    /**
     * Total matching rows, ignoring LIMIT/OFFSET/ORDER BY.
     *
     * Uses the current WHERE / tenant / soft-delete state so the result
     * reflects what fetchAll() would return if LIMIT were removed.
     */
    public function count(): int
    {
        [$whereSql, $params] = $this->buildWhereAndParams();
        $metadata = $this->metadata();
        $sql = sprintf('SELECT COUNT(*) AS __c FROM `%s`%s', $metadata->tableName, $whereSql);
        $result = $this->adapter->execute($sql, $params);
        $column = $result->fetchColumn();

        return is_numeric($column) ? (int) $column : 0;
    }

    /**
     * Whether at least one row matches — implemented as a cheap `LIMIT 1` probe.
     */
    public function exists(): bool
    {
        [$whereSql, $params] = $this->buildWhereAndParams();
        $metadata = $this->metadata();
        $sql = sprintf('SELECT 1 FROM `%s`%s LIMIT 1', $metadata->tableName, $whereSql);
        $result = $this->adapter->execute($sql, $params);

        return $result->rows !== [];
    }

    /**
     * Paginate the query (1-indexed pages).
     *
     * Executes one COUNT(*) query and one SELECT with LIMIT/OFFSET applied.
     * The returned `items` are resource-model instances; map them to
     * domain models via {@see PaginatedResult::map()} if needed.
     *
     * @return PaginatedResult<object>
     */
    public function paginate(int $page, int $perPage): PaginatedResult
    {
        if ($page < 1) {
            throw new \InvalidArgumentException('paginate() expects page >= 1.');
        }
        if ($perPage < 1) {
            throw new \InvalidArgumentException('paginate() expects perPage >= 1.');
        }

        $total = $this->count();

        $pageClone = clone $this;
        $pageClone->limitValue = $perPage;
        $pageClone->offsetValue = ($page - 1) * $perPage;

        return new PaginatedResult(
            items: $pageClone->fetchAll(),
            total: $total,
            page: $page,
            perPage: $perPage,
        );
    }

    /**
     * Paginate and map to a domain model.
     *
     * @return PaginatedResult<object>
     */
    public function paginateAs(int $page, int $perPage, string $domainModelClass, MapperRegistry $mapperRegistry): PaginatedResult
    {
        $raw = $this->paginate($page, $perPage);

        return $raw->map(
            static fn (object $rm): object => $mapperRegistry->mapToDomain($rm, $domainModelClass),
        );
    }

    // ------------------------------------------------------------------
    // Introspection / debug
    // ------------------------------------------------------------------

    public function toSql(): string
    {
        return $this->buildSql();
    }

    /**
     * @return array<string, mixed>
     */
    public function toParams(): array
    {
        return $this->buildParams();
    }

    /**
     * Produce a SQL string with parameter values interpolated — for logs
     * and debug output only. Not safe for execution.
     */
    public function toDebugSql(): string
    {
        $sql = $this->buildSql();
        $params = $this->buildParams();

        // Longest names first so :tenant_scope is replaced before :tenant.
        uksort($params, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($params as $name => $value) {
            $sql = str_replace(':' . $name, $this->formatDebugValue($value), $sql);
        }

        return $sql;
    }

    // ------------------------------------------------------------------
    // SQL assembly
    // ------------------------------------------------------------------

    private function buildSql(): string
    {
        $metadata = $this->metadata();
        $this->assertRequiredPoliciesAreSatisfied($metadata);

        [$whereSql, ] = $this->buildWhereAndParams();
        $sql = sprintf('SELECT * FROM `%s`%s', $metadata->tableName, $whereSql);

        if ($this->orderBys !== []) {
            $parts = [];
            foreach ($this->orderBys as $orderBy) {
                $parts[] = sprintf(
                    '`%s` %s',
                    $orderBy['column']->columnName,
                    $orderBy['direction']->value,
                );
            }
            $sql .= ' ORDER BY ' . implode(', ', $parts);
        }

        // MySQL rejects OFFSET without LIMIT. Emit a sentinel LIMIT that
        // covers any realistic result set when OFFSET is used without an
        // explicit LIMIT, preserving caller intent.
        if ($this->limitValue !== null) {
            $sql .= sprintf(' LIMIT %d', $this->limitValue);
            if ($this->offsetValue !== null) {
                $sql .= sprintf(' OFFSET %d', $this->offsetValue);
            }
        } elseif ($this->offsetValue !== null) {
            $sql .= sprintf(' LIMIT %d OFFSET %d', PHP_INT_MAX, $this->offsetValue);
        }

        return $sql;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildParams(): array
    {
        [, $params] = $this->buildWhereAndParams();

        return $params;
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhereAndParams(): array
    {
        $metadata = $this->metadata();
        $this->assertRequiredPoliciesAreSatisfied($metadata);

        /** @var list<SqlCondition> $policyConditions */
        $policyConditions = [];
        /** @var list<SqlCondition> $userConditions */
        $userConditions = [];
        $params = [];

        if ($metadata->tenantPolicy !== null && !$this->skipTenantScope) {
            $tenantColumn = $this->resolveTenantColumnName($metadata);
            $policyConditions[] = [
                'connector' => 'AND',
                'sql' => sprintf('`%s` = :tenant_scope', $tenantColumn),
            ];
            $params['tenant_scope'] = $this->tenantValue;
        }

        if ($metadata->softDelete !== null) {
            if ($this->onlySoftDeleted) {
                $policyConditions[] = [
                    'connector' => 'AND',
                    'sql' => sprintf('`%s` IS NOT NULL', $metadata->softDelete->columnName),
                ];
            } elseif (!$this->includeSoftDeleted) {
                $policyConditions[] = [
                    'connector' => 'AND',
                    'sql' => sprintf('`%s` IS NULL', $metadata->softDelete->columnName),
                ];
            }
        }

        foreach ($this->wheres as $where) {
            [$fragment, $wParams] = $this->renderWhere($where);
            $userConditions[] = [
                'connector' => $where['connector'],
                'sql' => $fragment,
            ];
            foreach ($wParams as $key => $value) {
                $params[$key] = $value;
            }
        }

        $conditions = $policyConditions;
        if ($userConditions !== []) {
            $userSql = '';
            foreach ($userConditions as $index => $condition) {
                if ($index === 0) {
                    $userSql .= $condition['sql'];
                    continue;
                }

                $userSql .= ' ' . $condition['connector'] . ' ' . $condition['sql'];
            }

            $conditions[] = [
                'connector' => 'AND',
                'sql' => count($userConditions) === 1 ? $userSql : '(' . $userSql . ')',
            ];
        }

        if ($conditions === []) {
            return ['', $params];
        }

        $sql = ' WHERE ';
        foreach ($conditions as $index => $condition) {
            if ($index === 0) {
                $sql .= $condition['sql'];
                continue;
            }
            $sql .= ' ' . $condition['connector'] . ' ' . $condition['sql'];
        }

        return [$sql, $params];
    }

    /**
     * @param WhereClause $where
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function renderWhere(array $where): array
    {
        return match ($where['kind']) {
            'comparison' => [
                sprintf(
                    '`%s` %s :%s',
                    $where['column']->columnName,
                    $where['operator']->value,
                    $where['param'],
                ),
                [$where['param'] => $where['value']],
            ],
            'null' => [
                sprintf(
                    '`%s` IS %sNULL',
                    $where['column']->columnName,
                    $where['negated'] ? 'NOT ' : '',
                ),
                [],
            ],
            'in' => [
                sprintf(
                    '`%s` %s (%s)',
                    $where['column']->columnName,
                    $where['negated'] ? 'NOT IN' : 'IN',
                    implode(', ', array_map(static fn (string $name): string => ':' . $name, $where['params'])),
                ),
                array_combine($where['params'], $where['values']),
            ],
            'between' => [
                sprintf(
                    '`%s` BETWEEN :%s AND :%s',
                    $where['column']->columnName,
                    $where['fromParam'],
                    $where['toParam'],
                ),
                [
                    $where['fromParam'] => $where['from'],
                    $where['toParam'] => $where['to'],
                ],
            ],
            'raw' => [
                '(' . $where['sql'] . ')',
                $where['params'],
            ],
        };
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param 'AND'|'OR' $connector
     */
    private function addNull(ColumnRef $column, bool $negated, string $connector): self
    {
        $this->assertColumnBelongsToCurrentResourceModel($column);
        $this->wheres[] = [
            'kind' => 'null',
            'connector' => $connector,
            'column' => $column,
            'negated' => $negated,
        ];

        return $this;
    }

    /**
     * @param list<mixed> $values
     * @param 'AND'|'OR' $connector
     */
    private function addIn(ColumnRef $column, array $values, bool $negated, string $connector): self
    {
        $this->assertColumnBelongsToCurrentResourceModel($column);

        if ($values === []) {
            // `x IN ()` is invalid SQL; use a guaranteed-false/true predicate
            // so the overall boolean result is stable.
            $this->wheres[] = [
                'kind' => 'raw',
                'connector' => $connector,
                'sql' => $negated ? '1 = 1' : '1 = 0',
                'params' => [],
            ];

            return $this;
        }

        $params = [];
        $casted = [];
        foreach ($values as $value) {
            $name = $this->nextParam('in');
            $params[] = $name;
            $casted[] = $this->normalizeComparisonValue($column, $value);
        }

        $this->wheres[] = [
            'kind' => 'in',
            'connector' => $connector,
            'column' => $column,
            'negated' => $negated,
            'params' => $params,
            'values' => $casted,
        ];

        return $this;
    }

    private function nextParam(string $hint = 'w'): string
    {
        return sprintf('%s%d', $hint, $this->paramCounter++);
    }

    private function metadata(): ResourceModelMetadata
    {
        return ($this->metadataRegistry ?? ResourceModelMetadataRegistry::default())->for($this->resourceModelClass);
    }

    private function resolveTenantColumnName(ResourceModelMetadata $metadata): string
    {
        $tenantColumn = $metadata->tenantPolicy->column ?? 'tenantId';

        if ($metadata->hasColumn($tenantColumn)) {
            return $metadata->column($tenantColumn)->columnName;
        }

        return $tenantColumn;
    }

    private function assertColumnBelongsToCurrentResourceModel(ColumnRef $column): void
    {
        if ($column->resourceModelClass !== $this->resourceModelClass) {
            throw new \InvalidArgumentException(sprintf(
                'ColumnRef for %s cannot be used in query for %s.',
                $column->resourceModelClass,
                $this->resourceModelClass,
            ));
        }
    }

    private function assertRelationBelongsToCurrentResourceModel(RelationRef $relation): void
    {
        if ($relation->resourceModelClass !== $this->resourceModelClass) {
            throw new \InvalidArgumentException(sprintf(
                'RelationRef for %s cannot be used in query for %s.',
                $relation->resourceModelClass,
                $this->resourceModelClass,
            ));
        }
    }

    private function assertRequiredPoliciesAreSatisfied(ResourceModelMetadata $metadata): void
    {
        if ($metadata->tenantPolicy !== null && !$this->skipTenantScope && $this->tenantValue === null) {
            throw new \LogicException(sprintf(
                'Query for tenant-scoped resource model %s requires tenant context. Call forTenant() or withoutTenantScope().',
                $this->resourceModelClass,
            ));
        }
    }

    private function normalizeComparisonValue(ColumnRef $column, mixed $value): mixed
    {
        $metadata = $this->metadata();
        $columnMetadata = $metadata->column($column->propertyName);
        $typeCaster = $this->typeCaster ?? new TypeCaster();

        return $typeCaster->castToDb(
            $value instanceof \BackedEnum ? $value->value : $value,
            $this->toColumnDefinition($columnMetadata),
        );
    }

    private function toColumnDefinition(ColumnMetadata $column): ColumnDefinition
    {
        return new ColumnDefinition(
            name: $column->columnName,
            type: $column->type,
            phpType: $column->phpType,
            nullable: $column->nullable,
            length: $column->length,
            precision: $column->precision,
            scale: $column->scale,
            default: $column->default,
            isPrimaryKey: $column->isPrimaryKey,
            pkStrategy: $column->primaryKeyStrategy ?? 'auto',
            propertyName: $column->propertyName,
        );
    }

    private function formatDebugValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d H:i:s');
        }
        if ($value instanceof \Stringable) {
            $value = (string) $value;
        }
        if (is_string($value)) {
            return "'" . str_replace("'", "''", $value) . "'";
        }
        if (is_scalar($value)) {
            return "'" . str_replace("'", "''", (string) $value) . "'";
        }
        if ($value instanceof \BackedEnum) {
            return "'" . str_replace("'", "''", (string) $value->value) . "'";
        }

        return "'" . str_replace("'", "''", json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: get_debug_type($value)) . "'";
    }
}

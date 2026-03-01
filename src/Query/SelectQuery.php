<?php

declare(strict_types=1);

namespace Semitexa\Orm\Query;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Attribute\Aggregate;
use Semitexa\Orm\Hydration\RelationType;
use Semitexa\Orm\Hydration\StreamingHydrator;
use Semitexa\Orm\Repository\PaginatedResult;
use Semitexa\Orm\Schema\ResourceMetadata;

class SelectQuery implements WhereCapableInterface
{
    use WhereTrait;

    private string $table;
    private string $resourceClass;
    private DatabaseAdapterInterface $adapter;
    private StreamingHydrator $hydrator;

    /** @var string[] */
    private array $columns = ['*'];

    /**
     * Each entry describes one WHERE condition:
     *   connector  — 'AND' | 'OR' (how it joins with the previous condition)
     *   type       — 'basic' | 'null' | 'in' | 'not_in' | 'like' | 'between' | 'raw'
     *   column     — column name (unused for 'raw')
     *   operator   — SQL operator string (used by 'basic' and 'null')
     *   param      — named param key(s) or null ('null'/'raw' types)
     *   sql        — raw SQL fragment ('raw' type only)
     *   rawParams  — positional params for 'raw' type
     * @var array<int, array<string, mixed>>
     */
    private array $wheres = [];

    /** @var array{column: string, direction: string}[] */
    private array $orderBys = [];

    private ?int $limitValue = null;
    private ?int $offsetValue = null;

    /**
     * Relation filter: null = load all, [] = load none, ['prop', …] = load only listed.
     * @var string[]|null
     */
    private ?array $withRelations = null;

    /** @var array<string, mixed> */
    private array $params = [];
    private int $paramCounter = 0;

    /** @var array{alias: string, sql: string}[]|null Cached aggregate subqueries */
    private ?array $aggregateSubqueries = null;

    /**
     * Relation filter criteria from applyRelationCriteria(): relationProperty => [column => value].
     * @var array<string, array<string, mixed>>
     */
    private array $relationCriteria = [];

    /**
     * Extra relation conditions from whereRelation(): list of [relation, column, operator, value].
     * @var array<int, array{relation: string, column: string, operator: string, value: mixed}>
     */
    private array $relationWhereConditions = [];

    /** Cached JOIN and WHERE SQL for relation filters (built once per query) */
    private ?string $relationJoinSql = null;
    private ?string $relationWhereSql = null;

    public function __construct(
        string $table,
        string $resourceClass,
        DatabaseAdapterInterface $adapter,
        StreamingHydrator $hydrator,
    ) {
        $this->table = $table;
        $this->resourceClass = $resourceClass;
        $this->adapter = $adapter;
        $this->hydrator = $hydrator;
    }

    /**
     * @param string[] $columns
     */
    public function columns(array $columns): self
    {
        $this->columns = $columns;
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException("Invalid order direction: {$direction}");
        }

        $this->orderBys[] = ['column' => $column, 'direction' => $direction];
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limitValue = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offsetValue = $offset;
        return $this;
    }

    /**
     * Load only the specified relation properties.
     * Call with no arguments or with an explicit list:
     *   ->with('author', 'tags')   // load only author + tags
     *   ->with()                   // same as default: load all (resets any previous filter)
     */
    public function with(string ...$relations): self
    {
        $this->withRelations = $relations === [] ? null : array_values($relations);
        return $this;
    }

    /**
     * Skip all relation loading for this query.
     * Useful when only base columns are needed and relations are expensive.
     */
    public function withoutRelations(): self
    {
        $this->withRelations = [];
        return $this;
    }

    /**
     * Apply relation filter criteria (e.g. from FilterableResourceInterface::getRelationFilterCriteria()).
     * Keys are relation property names; values are [DB column name => value] on the related table.
     * Same value semantics as main table: null => IS NULL, array => IN, scalar => =.
     *
     * @param array<string, array<string, mixed>> $criteria
     */
    public function applyRelationCriteria(array $criteria): self
    {
        $this->relationCriteria = array_merge($this->relationCriteria, $criteria);
        $this->relationJoinSql = null;
        $this->relationWhereSql = null;
        return $this;
    }

    /**
     * Restrict results where the related entity (or at least one for HasMany/ManyToMany) satisfies the condition.
     */
    public function whereRelation(string $relationProperty, string $column, string $operator, mixed $value): self
    {
        $this->relationWhereConditions[] = [
            'relation'  => $relationProperty,
            'column'    => $column,
            'operator'  => $operator,
            'value'     => $value,
        ];
        $this->relationJoinSql = null;
        $this->relationWhereSql = null;
        return $this;
    }

    /**
     * Execute and return all results as Domain objects.
     *
     * @return object[]
     */
    public function fetchAll(): array
    {
        $sql = $this->buildSql();
        $result = $this->adapter->execute($sql, $this->params);

        return $this->hydrator->hydrateAllToDomain($result->rows, $this->resourceClass, $this->withRelations);
    }

    /**
     * Execute and return the first result or null.
     */
    public function fetchOne(): ?object
    {
        // Build SQL with LIMIT 1 without mutating internal state.
        // Uses buildCoreSql() (no LIMIT/OFFSET) so any ->limit() set on the
        // query object does not bleed into this single-row fetch.
        $sql = $this->buildCoreSql() . ' LIMIT 1';
        $result = $this->adapter->execute($sql, $this->params);
        $row = $result->fetchOne();

        if ($row === null) {
            return null;
        }

        return $this->hydrator->hydrateToDomain($row, $this->resourceClass, $this->withRelations);
    }

    /**
     * Execute and return the first result as Resource, or null.
     * Use when the caller needs the Resource (e.g. password_hash, save()) rather than the domain object.
     */
    public function fetchOneAsResource(): ?object
    {
        $sql = $this->buildCoreSql() . ' LIMIT 1';
        $result = $this->adapter->execute($sql, $this->params);
        $row = $result->fetchOne();

        if ($row === null) {
            return null;
        }

        return $this->hydrator->hydrateToResource($row, $this->resourceClass);
    }

    /**
     * Execute paginated query and return PaginatedResult with Domain objects.
     */
    public function paginate(int $page, int $perPage = 20): PaginatedResult
    {
        if ($page < 1) {
            throw new \InvalidArgumentException("Page must be >= 1, got {$page}.");
        }
        if ($perPage < 1) {
            throw new \InvalidArgumentException("PerPage must be >= 1, got {$perPage}.");
        }

        // Count total — uses current WHERE but no LIMIT/OFFSET
        $countSql = $this->buildCountSql();
        $countResult = $this->adapter->execute($countSql, $this->params);
        $total = (int) $countResult->fetchColumn();

        // Build page SQL without mutating internal state: use buildCoreSql()
        // (no LIMIT/OFFSET) and append pagination clauses directly.
        $sql = $this->buildCoreSql() . sprintf(' LIMIT %d OFFSET %d', $perPage, ($page - 1) * $perPage);
        $result = $this->adapter->execute($sql, $this->params);

        $items = $this->hydrator->hydrateAllToDomain($result->rows, $this->resourceClass, $this->withRelations);

        return new PaginatedResult(
            items: $items,
            total: $total,
            page: $page,
            perPage: $perPage,
        );
    }

    /**
     * Get the raw count for this query.
     */
    public function count(): int
    {
        $sql = $this->buildCountSql();
        $result = $this->adapter->execute($sql, $this->params);
        return (int) $result->fetchColumn();
    }

    public function buildSql(): string
    {
        $sql = $this->buildCoreSql();

        if ($this->limitValue !== null) {
            $sql .= " LIMIT {$this->limitValue}";
        }
        if ($this->offsetValue !== null) {
            $sql .= " OFFSET {$this->offsetValue}";
        }

        return $sql;
    }

    /**
     * Build SELECT … FROM … WHERE … ORDER BY — without LIMIT/OFFSET.
     * Used internally by fetchOne() and paginate() to append their own
     * pagination clauses without touching $this->limitValue/$this->offsetValue.
     */
    private function buildCoreSql(): string
    {
        $cols = implode(', ', array_map(fn(string $c) => $c === '*' ? "`{$this->table}`.*" : "`{$c}`", $this->columns));

        $aggregates = $this->getAggregateSubqueries();
        foreach ($aggregates as $agg) {
            $cols .= ", ({$agg['sql']}) AS `{$agg['alias']}`";
        }

        $sql = "SELECT {$cols} FROM `{$this->table}`";
        $sql .= $this->getRelationJoinSql();
        $mainWhere = $this->buildWhereClause();
        $relWhere  = $this->getRelationWhereSql();
        if ($mainWhere !== '' || $relWhere !== '') {
            $sql .= $mainWhere !== '' ? $mainWhere . $relWhere : ' WHERE 1=1' . $relWhere;
        }
        $sql .= $this->buildOrderByClause();

        return $sql;
    }

    private function buildCountSql(): string
    {
        $sql = "SELECT COUNT(*) FROM `{$this->table}`";
        $sql .= $this->getRelationJoinSql();
        $mainWhere = $this->buildWhereClause();
        $relWhere  = $this->getRelationWhereSql();
        if ($mainWhere !== '' || $relWhere !== '') {
            $sql .= $mainWhere !== '' ? $mainWhere . $relWhere : ' WHERE 1=1' . $relWhere;
        }
        return $sql;
    }

    private function getRelationJoinSql(): string
    {
        $this->resolveRelationFilters();
        return $this->relationJoinSql ?? '';
    }

    private function getRelationWhereSql(): string
    {
        $this->resolveRelationFilters();
        return $this->relationWhereSql ?? '';
    }

    /**
     * Build relation JOINs (or subquery WHERE) and relation WHERE conditions; cache result.
     */
    private function resolveRelationFilters(): void
    {
        if ($this->relationJoinSql !== null && $this->relationWhereSql !== null) {
            return;
        }
        $allRelations = array_unique(array_merge(
            array_keys($this->relationCriteria),
            array_column($this->relationWhereConditions, 'relation')
        ));
        if ($allRelations === []) {
            $this->relationJoinSql = '';
            $this->relationWhereSql = '';
            return;
        }

        $meta = ResourceMetadata::for($this->resourceClass);
        $mainPk = $meta->getPkColumn();
        $joinParts = [];
        $whereParts = [];

        foreach ($allRelations as $relationProperty) {
            $relationMeta = $meta->getRelationByProperty($relationProperty);
            if ($relationMeta === null) {
                throw new \InvalidArgumentException(
                    "Unknown relation '{$relationProperty}' on " . $this->resourceClass . '.'
                );
            }

            $criteria = $this->relationCriteria[$relationProperty] ?? [];
            $extraConditions = array_filter(
                $this->relationWhereConditions,
                fn($c) => $c['relation'] === $relationProperty
            );

            if ($relationMeta->type === RelationType::BelongsTo || $relationMeta->type === RelationType::OneToOne) {
                $targetMeta = ResourceMetadata::for($relationMeta->targetClass);
                $alias = 'rel_' . $relationProperty;
                $joinParts[] = " JOIN `{$targetMeta->getTableName()}` AS `{$alias}` ON `{$this->table}`.`{$relationMeta->foreignKey}` = `{$alias}`.`{$targetMeta->getPkColumn()}`";
                $this->appendRelationWhereParts($whereParts, $alias, $relationMeta->targetClass, $criteria, $extraConditions);
            } elseif ($relationMeta->type === RelationType::HasMany || $relationMeta->type === RelationType::ManyToMany) {
                $subqueryWhere = $this->buildRelationSubqueryWhere($relationMeta, $criteria, $extraConditions);
                if ($subqueryWhere !== '') {
                    $whereParts[] = $subqueryWhere;
                }
            }
        }

        $this->relationJoinSql = implode('', $joinParts);
        $this->relationWhereSql = $whereParts === [] ? '' : ' AND ' . implode(' AND ', $whereParts);
    }

    /**
     * Append WHERE conditions for a JOIN-based relation (BelongsTo/OneToOne).
     */
    private function appendRelationWhereParts(
        array &$whereParts,
        string $alias,
        string $relatedClass,
        array $criteria,
        array $extraConditions
    ): void {
        $relatedMeta = ResourceMetadata::for($relatedClass);
        $filterableColumns = array_values($relatedMeta->getFilterableColumns());
        foreach ($criteria as $column => $value) {
            if (!in_array($column, $filterableColumns, true)) {
                throw new \InvalidArgumentException(
                    "Column '{$column}' is not filterable on {$relatedClass}."
                );
            }
            $qualified = "{$alias}.{$column}";
            if ($value === null) {
                $whereParts[] = "`{$alias}`.`{$column}` IS NULL";
            } elseif (is_array($value)) {
                $paramNames = [];
                foreach ($value as $v) {
                    $key = $this->nextParam($qualified);
                    $paramNames[] = ':' . $key;
                    $this->params[$key] = $v instanceof \BackedEnum ? $v->value : $v;
                }
                $whereParts[] = "`{$alias}`.`{$column}` IN (" . implode(', ', $paramNames) . ")";
            } else {
                $key = $this->nextParam($qualified);
                $whereParts[] = "`{$alias}`.`{$column}` = :{$key}";
                $this->params[$key] = $value instanceof \BackedEnum ? $value->value : $value;
            }
        }
        foreach ($extraConditions as $c) {
            $key = $this->nextParam($alias . '_' . $c['column']);
            $op = strtoupper($c['operator']);
            if (!in_array($op, ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE'], true)) {
                throw new \InvalidArgumentException("Invalid operator for whereRelation: {$c['operator']}");
            }
            $whereParts[] = "`{$alias}`.`{$c['column']}` {$op} :{$key}";
            $this->params[$key] = $c['value'] instanceof \BackedEnum ? $c['value']->value : $c['value'];
        }
    }

    /**
     * Build a single WHERE fragment for HasMany/ManyToMany: main_table.pk IN (subquery).
     */
    private function buildRelationSubqueryWhere(
        \Semitexa\Orm\Hydration\RelationMeta $relationMeta,
        array $criteria,
        array $extraConditions
    ): string {
        $meta = ResourceMetadata::for($this->resourceClass);
        $mainPk = $meta->getPkColumn();
        $targetMeta = ResourceMetadata::for($relationMeta->targetClass);
        $targetTable = $targetMeta->getTableName();
        $targetPk = $targetMeta->getPkColumn();

        $subWhere = [];
        $subParams = [];
        $paramPrefix = 'subq_' . $relationMeta->property . '_';
        $i = 0;

        foreach ($criteria as $column => $value) {
            $i++;
            $k = $paramPrefix . $i;
            if ($value === null) {
                $subWhere[] = "`{$targetTable}`.`{$column}` IS NULL";
            } elseif (is_array($value)) {
                $placeholders = [];
                foreach ($value as $v) {
                    $i++;
                    $kk = $paramPrefix . $i;
                    $placeholders[] = ":{$kk}";
                    $subParams[$kk] = $v instanceof \BackedEnum ? $v->value : $v;
                }
                $subWhere[] = "`{$targetTable}`.`{$column}` IN (" . implode(', ', $placeholders) . ")";
            } else {
                $subWhere[] = "`{$targetTable}`.`{$column}` = :{$k}";
                $subParams[$k] = $value instanceof \BackedEnum ? $value->value : $value;
            }
        }
        foreach ($extraConditions as $c) {
            $i++;
            $k = $paramPrefix . $i;
            $op = strtoupper($c['operator']);
            $subWhere[] = "`{$targetTable}`.`{$c['column']}` {$op} :{$k}";
            $subParams[$k] = $c['value'] instanceof \BackedEnum ? $c['value']->value : $c['value'];
        }

        if ($subWhere === []) {
            return '';
        }

        foreach ($subParams as $k => $v) {
            $this->params[$k] = $v;
        }

        $subWhereSql = implode(' AND ', $subWhere);

        if ($relationMeta->type === RelationType::HasMany) {
            return "`{$this->table}`.`{$mainPk}` IN (SELECT `{$relationMeta->foreignKey}` FROM `{$targetTable}` WHERE {$subWhereSql})";
        }

        $pivot = $relationMeta->pivotTable;
        $fk = $relationMeta->foreignKey;
        $rk = $relationMeta->relatedKey;
        return "`{$this->table}`.`{$mainPk}` IN (SELECT `{$fk}` FROM `{$pivot}` WHERE `{$rk}` IN (SELECT `{$targetPk}` FROM `{$targetTable}` WHERE {$subWhereSql}))";
    }

    private function buildOrderByClause(): string
    {
        if ($this->orderBys === []) {
            return '';
        }

        $parts = array_map(
            fn(array $o) => "`{$o['column']}` {$o['direction']}",
            $this->orderBys,
        );

        return ' ORDER BY ' . implode(', ', $parts);
    }

    /**
     * Detect #[Aggregate] properties on the Resource class and build correlated subqueries.
     *
     * @return array{alias: string, sql: string}[]
     */
    private function getAggregateSubqueries(): array
    {
        if ($this->aggregateSubqueries !== null) {
            return $this->aggregateSubqueries;
        }

        $this->aggregateSubqueries = [];
        $ref = new \ReflectionClass($this->resourceClass);
        $pk  = ResourceMetadata::for($this->resourceClass)->getPkColumn();

        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $attrs = $prop->getAttributes(Aggregate::class);
            if ($attrs === []) {
                continue;
            }

            /** @var Aggregate $agg */
            $agg = $attrs[0]->newInstance();

            $parts = explode('.', $agg->field, 2);
            if (count($parts) !== 2) {
                continue;
            }

            [$relatedTable, $relatedColumn] = $parts;
            $function = strtoupper($agg->function);

            $allowedFunctions = ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'];
            if (!in_array($function, $allowedFunctions, true)) {
                continue;
            }

            $foreignKey = $agg->foreignKey ?? $this->guessRelatedForeignKey($this->table);

            $this->aggregateSubqueries[] = [
                'alias' => $prop->getName(),
                'sql' => "SELECT {$function}(`{$relatedTable}`.`{$relatedColumn}`) FROM `{$relatedTable}` WHERE `{$relatedTable}`.`{$foreignKey}` = `{$this->table}`.`{$pk}`",
            ];
        }

        return $this->aggregateSubqueries;
    }

    /**
     * Guess FK column name on a related table pointing to the parent table.
     * Convention: singular form of parent table + "_id".
     * e.g. "orders" -> "order_id", "users" -> "user_id"
     */
    private function guessRelatedForeignKey(string $parentTable): string
    {
        $singular = rtrim($parentTable, 's');
        return $singular . '_id';
    }
}

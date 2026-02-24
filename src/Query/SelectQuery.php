<?php

declare(strict_types=1);

namespace Semitexa\Orm\Query;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Attribute\Aggregate;
use Semitexa\Orm\Hydration\StreamingHydrator;
use Semitexa\Orm\Repository\PaginatedResult;
use Semitexa\Orm\Schema\ResourceMetadata;

class SelectQuery
{
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

    private const VALID_OPERATORS = ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE'];

    public function where(string $column, string $operator, mixed $value): self
    {
        $this->assertValidOperator($operator);
        $paramName = $this->nextParam($column);
        $this->wheres[] = [
            'connector' => 'AND',
            'type'      => 'basic',
            'column'    => $column,
            'operator'  => $operator,
            'param'     => $paramName,
        ];
        $this->params[$paramName] = $value instanceof \BackedEnum ? $value->value : $value;
        return $this;
    }

    public function orWhere(string $column, string $operator, mixed $value): self
    {
        $this->assertValidOperator($operator);
        $paramName = $this->nextParam($column);
        $this->wheres[] = [
            'connector' => 'OR',
            'type'      => 'basic',
            'column'    => $column,
            'operator'  => $operator,
            'param'     => $paramName,
        ];
        $this->params[$paramName] = $value instanceof \BackedEnum ? $value->value : $value;
        return $this;
    }

    private function assertValidOperator(string $operator): void
    {
        if (!in_array(strtoupper($operator), self::VALID_OPERATORS, true)) {
            throw new \InvalidArgumentException(
                "Invalid WHERE operator '{$operator}'. Allowed: " . implode(', ', self::VALID_OPERATORS)
            );
        }
    }

    public function whereNull(string $column): self
    {
        $this->wheres[] = [
            'connector' => 'AND',
            'type'      => 'null',
            'column'    => $column,
            'operator'  => 'IS NULL',
            'param'     => null,
        ];
        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->wheres[] = [
            'connector' => 'AND',
            'type'      => 'null',
            'column'    => $column,
            'operator'  => 'IS NOT NULL',
            'param'     => null,
        ];
        return $this;
    }

    /**
     * @param list<mixed> $values
     */
    public function whereIn(string $column, array $values): self
    {
        $paramNames = [];
        foreach ($values as $val) {
            $paramName    = $this->nextParam($column);
            $paramNames[] = ':' . $paramName;
            $this->params[$paramName] = $val instanceof \BackedEnum ? $val->value : $val;
        }

        $this->wheres[] = [
            'connector' => 'AND',
            'type'      => 'in',
            'column'    => $column,
            'operator'  => 'IN',
            'param'     => $paramNames,
        ];
        return $this;
    }

    /**
     * @param list<mixed> $values
     */
    public function whereNotIn(string $column, array $values): self
    {
        $paramNames = [];
        foreach ($values as $val) {
            $paramName    = $this->nextParam($column);
            $paramNames[] = ':' . $paramName;
            $this->params[$paramName] = $val instanceof \BackedEnum ? $val->value : $val;
        }

        $this->wheres[] = [
            'connector' => 'AND',
            'type'      => 'not_in',
            'column'    => $column,
            'operator'  => 'NOT IN',
            'param'     => $paramNames,
        ];
        return $this;
    }

    public function whereLike(string $column, string $pattern): self
    {
        $paramName = $this->nextParam($column);
        $this->wheres[] = [
            'connector' => 'AND',
            'type'      => 'like',
            'column'    => $column,
            'operator'  => 'LIKE',
            'param'     => $paramName,
        ];
        $this->params[$paramName] = $pattern;
        return $this;
    }

    public function whereBetween(string $column, mixed $from, mixed $to): self
    {
        $paramFrom = $this->nextParam($column . '_from');
        $paramTo   = $this->nextParam($column . '_to');
        $this->wheres[] = [
            'connector' => 'AND',
            'type'      => 'between',
            'column'    => $column,
            'param'     => [$paramFrom, $paramTo],
        ];
        $this->params[$paramFrom] = $from;
        $this->params[$paramTo]   = $to;
        return $this;
    }

    /**
     * Append a raw SQL fragment to the WHERE clause (joined with AND).
     * Use positional ? placeholders — values are bound immediately.
     *
     * Example:
     *   ->whereRaw('MATCH(`title`) AGAINST(? IN BOOLEAN MODE)', [$term])
     *   ->whereRaw('`created_at` > DATE_SUB(NOW(), INTERVAL ? DAY)', [30])
     *
     * @param list<mixed> $bindings
     */
    public function whereRaw(string $sql, array $bindings = []): self
    {
        // Convert positional ? to named params immediately so that
        // buildWhereClause() stays idempotent (it may be called multiple
        // times — e.g. once for COUNT and once for the data query in paginate).
        foreach ($bindings as $val) {
            $key                = $this->nextParam('raw');
            $sql                = preg_replace('/\?/', ":{$key}", $sql, 1);
            $this->params[$key] = $val;
        }

        $this->wheres[] = [
            'connector' => 'AND',
            'type'      => 'raw',
            'sql'       => $sql,
        ];
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
        $sql .= $this->buildWhereClause();
        $sql .= $this->buildOrderByClause();

        return $sql;
    }

    private function buildCountSql(): string
    {
        return "SELECT COUNT(*) FROM `{$this->table}`" . $this->buildWhereClause();
    }

    private function buildWhereClause(): string
    {
        if ($this->wheres === []) {
            return '';
        }

        $parts = [];
        foreach ($this->wheres as $i => $where) {
            $connector = $i === 0 ? '' : " {$where['connector']} ";
            $parts[]   = $connector . $this->buildWhereCondition($where);
        }

        return ' WHERE ' . implode('', $parts);
    }

    /**
     * @param array<string, mixed> $where
     */
    private function buildWhereCondition(array $where): string
    {
        $type = $where['type'];

        if ($type === 'raw') {
            // Bindings already converted to named params in whereRaw().
            return $where['sql'];
        }

        $col = "`{$where['column']}`";

        if ($type === 'null') {
            return "{$col} {$where['operator']}";
        }

        if ($type === 'in' || $type === 'not_in') {
            $inList = implode(', ', $where['param']);
            return "{$col} {$where['operator']} ({$inList})";
        }

        if ($type === 'between') {
            [$paramFrom, $paramTo] = $where['param'];
            return "{$col} BETWEEN :{$paramFrom} AND :{$paramTo}";
        }

        // basic / like
        return "{$col} {$where['operator']} :{$where['param']}";
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

    private function nextParam(string $column): string
    {
        $this->paramCounter++;
        // Clean column name for param (alphanumeric only)
        $clean = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
        return "p_{$clean}_{$this->paramCounter}";
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

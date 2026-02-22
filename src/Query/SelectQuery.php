<?php

declare(strict_types=1);

namespace Semitexa\Orm\Query;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Attribute\Aggregate;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Hydration\StreamingHydrator;
use Semitexa\Orm\Repository\PaginatedResult;

class SelectQuery
{
    private string $table;
    private string $resourceClass;
    private DatabaseAdapterInterface $adapter;
    private StreamingHydrator $hydrator;

    /** @var string[] */
    private array $columns = ['*'];

    /** @var array{column: string, operator: string, value: mixed}[] */
    private array $wheres = [];

    /** @var array{column: string, direction: string}[] */
    private array $orderBys = [];

    private ?int $limitValue = null;
    private ?int $offsetValue = null;

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

    public function where(string $column, string $operator, mixed $value): self
    {
        $paramName = $this->nextParam($column);
        $this->wheres[] = [
            'column' => $column,
            'operator' => $operator,
            'param' => $paramName,
        ];
        $this->params[$paramName] = $value instanceof \BackedEnum ? $value->value : $value;
        return $this;
    }

    public function whereNull(string $column): self
    {
        $this->wheres[] = [
            'column' => $column,
            'operator' => 'IS NULL',
            'param' => null,
        ];
        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->wheres[] = [
            'column' => $column,
            'operator' => 'IS NOT NULL',
            'param' => null,
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
            $paramName = $this->nextParam($column);
            $paramNames[] = ':' . $paramName;
            $this->params[$paramName] = $val instanceof \BackedEnum ? $val->value : $val;
        }

        $this->wheres[] = [
            'column' => $column,
            'operator' => 'IN',
            'param' => $paramNames,
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
     * Execute and return all results as Domain objects.
     *
     * @return object[]
     */
    public function fetchAll(): array
    {
        $sql = $this->buildSql();
        $stmt = $this->adapter->execute($sql, $this->params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $this->hydrator->hydrateAllToDomain($rows, $this->resourceClass);
    }

    /**
     * Execute and return the first result or null.
     */
    public function fetchOne(): ?object
    {
        $this->limitValue = 1;
        $sql = $this->buildSql();
        $stmt = $this->adapter->execute($sql, $this->params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->hydrator->hydrateToDomain($row, $this->resourceClass);
    }

    /**
     * Execute paginated query and return PaginatedResult with Domain objects.
     */
    public function paginate(int $page, int $perPage = 20): PaginatedResult
    {
        // Count total
        $countSql = $this->buildCountSql();
        $countStmt = $this->adapter->execute($countSql, $this->params);
        $total = (int) $countStmt->fetchColumn();

        // Fetch page
        $this->limitValue = $perPage;
        $this->offsetValue = ($page - 1) * $perPage;

        $sql = $this->buildSql();
        $stmt = $this->adapter->execute($sql, $this->params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $items = $this->hydrator->hydrateAllToDomain($rows, $this->resourceClass);

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
        $stmt = $this->adapter->execute($sql, $this->params);
        return (int) $stmt->fetchColumn();
    }

    public function buildSql(): string
    {
        $cols = implode(', ', array_map(fn(string $c) => $c === '*' ? "`{$this->table}`.*" : "`{$c}`", $this->columns));

        $aggregates = $this->getAggregateSubqueries();
        foreach ($aggregates as $agg) {
            $cols .= ", ({$agg['sql']}) AS `{$agg['alias']}`";
        }

        $sql = "SELECT {$cols} FROM `{$this->table}`";

        $sql .= $this->buildWhereClause();
        $sql .= $this->buildOrderByClause();

        if ($this->limitValue !== null) {
            $sql .= " LIMIT {$this->limitValue}";
        }
        if ($this->offsetValue !== null) {
            $sql .= " OFFSET {$this->offsetValue}";
        }

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

        $conditions = [];
        foreach ($this->wheres as $where) {
            $col = "`{$where['column']}`";

            if ($where['param'] === null) {
                // IS NULL / IS NOT NULL
                $conditions[] = "{$col} {$where['operator']}";
            } elseif ($where['operator'] === 'IN') {
                $inList = implode(', ', $where['param']);
                $conditions[] = "{$col} IN ({$inList})";
            } else {
                $conditions[] = "{$col} {$where['operator']} :{$where['param']}";
            }
        }

        return ' WHERE ' . implode(' AND ', $conditions);
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
        $pk = $this->resolvePkColumn();

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
     * Resolve the PK column name from the Resource class.
     */
    private function resolvePkColumn(): string
    {
        $ref = new \ReflectionClass($this->resourceClass);
        foreach ($ref->getProperties() as $prop) {
            if ($prop->getAttributes(PrimaryKey::class) !== []) {
                return $prop->getName();
            }
        }
        return 'id';
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

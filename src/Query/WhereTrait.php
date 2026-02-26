<?php

declare(strict_types=1);

namespace Semitexa\Orm\Query;

/**
 * Shared WHERE-building logic for SelectQuery, UpdateQuery, DeleteQuery.
 *
 * Using class must declare:
 *   private array $wheres = [];
 *   private array $params = [];
 *   private int $paramCounter = 0;
 */
trait WhereTrait
{
    private const VALID_OPERATORS = ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE'];

    public function where(string $column, string $operator, mixed $value): static
    {
        $this->assertValidOperator($operator);
        $paramName = $this->nextParam($column);
        $this->wheres[] = [
            'connector' => 'AND',
            'type'      => 'basic',
            'column'    => $column,
            'operator'  => strtoupper($operator),
            'param'     => $paramName,
        ];
        $this->params[$paramName] = $value instanceof \BackedEnum ? $value->value : $value;
        return $this;
    }

    public function orWhere(string $column, string $operator, mixed $value): static
    {
        $this->assertValidOperator($operator);
        $paramName = $this->nextParam($column);
        $this->wheres[] = [
            'connector' => 'OR',
            'type'      => 'basic',
            'column'    => $column,
            'operator'  => strtoupper($operator),
            'param'     => $paramName,
        ];
        $this->params[$paramName] = $value instanceof \BackedEnum ? $value->value : $value;
        return $this;
    }

    public function whereNull(string $column): static
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

    public function whereNotNull(string $column): static
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
    public function whereIn(string $column, array $values): static
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
    public function whereNotIn(string $column, array $values): static
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

    public function whereLike(string $column, string $pattern): static
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

    public function whereBetween(string $column, mixed $from, mixed $to): static
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
     * @param list<mixed> $bindings
     */
    public function whereRaw(string $sql, array $bindings = []): static
    {
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

    private function assertValidOperator(string $operator): void
    {
        if (!in_array(strtoupper($operator), self::VALID_OPERATORS, true)) {
            throw new \InvalidArgumentException(
                "Invalid WHERE operator '{$operator}'. Allowed: " . implode(', ', self::VALID_OPERATORS)
            );
        }
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
            return $where['sql'];
        }

        // Support qualified column (e.g. alias.column) for relation filters
        $col = str_contains($where['column'], '.')
            ? implode('.', array_map(fn(string $part) => '`' . $part . '`', explode('.', $where['column'], 2)))
            : "`{$where['column']}`";

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

    private function nextParam(string $column): string
    {
        $this->paramCounter++;
        $clean = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
        return "p_{$clean}_{$this->paramCounter}";
    }
}

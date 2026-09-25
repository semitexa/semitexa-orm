<?php

declare(strict_types=1);

namespace Semitexa\Orm\Query;

use Semitexa\Orm\Adapter\SqlIdentifier;

/**
 * Shared WHERE-building logic for query builders that support filtering.
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
     * THE FRAGMENT IS TRUSTED VERBATIM. The bindings are bound; $sql is not —
     * it is concatenated into the statement as given, which makes this the one
     * door in the ORM an injection can still come through, and it comes from
     * the caller:
     *
     *     ->whereRaw('`status` = ?', [$status])              // fine
     *     ->whereRaw('`name` = ' . $request->get('q'))       // INJECTION
     *
     * Every value belongs in a `?`; every identifier that is not written out
     * by hand belongs in SqlIdentifier. Same contract as
     * {@see \Semitexa\Orm\Query\ResourceModelQuery::whereRaw()}, stated
     * here too because a caller reaching for this trait does not read that one.
     *
     * @param list<mixed> $bindings
     */
    public function whereRaw(string $sql, array $bindings = []): static
    {
        // Placeholders inside quoted strings/identifiers and comments are
        // not bindings (same scanner as ResourceModelQuery::whereRaw()).
        $offsets = RawSqlScanner::placeholderOffsets($sql);
        $bindings = array_values($bindings);
        if (count($offsets) !== count($bindings)) {
            throw new \InvalidArgumentException(sprintf(
                'whereRaw() expects exactly %d binding(s), got %d.',
                count($offsets),
                count($bindings),
            ));
        }

        $keys = [];
        foreach ($bindings as $val) {
            $key                = $this->nextParam('raw');
            $keys[]             = $key;
            $this->params[$key] = $val instanceof \BackedEnum ? $val->value : $val;
        }
        // Right-to-left so earlier offsets stay valid as the string grows.
        for ($i = count($offsets) - 1; $i >= 0; $i--) {
            $sql = substr_replace($sql, ':' . $keys[$i], $offsets[$i], 1);
        }
        $this->wheres[] = [
            'connector' => 'AND',
            'type'      => 'raw',
            'sql'       => $sql,
        ];
        return $this;
    }

    /**
     * Collapse the staged conditions into one parenthesized group when they
     * contain an OR, so a condition appended afterwards narrows the whole
     * set: (a OR b) AND c, not a OR (b AND c).
     */
    private function groupStagedConditions(): void
    {
        foreach ($this->wheres as $i => $where) {
            if ($i > 0 && $where['connector'] === 'OR') {
                $this->wheres = [[
                    'connector' => 'AND',
                    'type'      => 'raw',
                    'sql'       => substr($this->buildWhereClause(), strlen(' WHERE ')),
                ]];

                return;
            }
        }
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

        // Parenthesized: a fragment containing OR must not bind looser than
        // the conditions it is ANDed with (`a = ? AND b = ? OR c = ?`).
        if ($type === 'raw') {
            return '(' . $where['sql'] . ')';
        }

        // Support qualified column (e.g. alias.column) for relation filters.
        // The backtick escaping this used to inline (VULN-004) is now
        // SqlIdentifier's, so every builder gets the same rule.
        $column = $where['column'];
        if (!is_string($column)) {
            throw new \LogicException('WHERE condition column must be a string.');
        }
        $col = SqlIdentifier::quoteQualified($column);

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

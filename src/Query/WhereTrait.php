<?php

declare(strict_types=1);

namespace Semitexa\Orm\Query;

/**
 * Shared WHERE-building logic for query builders that support filtering.
 *
 * Using class must declare:
 *   /** @var list<WhereCondition> *\/ private array $wheres = [];
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
        $this->wheres[] = WhereCondition::compare('AND', $column, strtoupper($operator), $paramName);
        $this->params[$paramName] = $value instanceof \BackedEnum ? $value->value : $value;
        return $this;
    }

    public function orWhere(string $column, string $operator, mixed $value): static
    {
        $this->assertValidOperator($operator);
        $paramName = $this->nextParam($column);
        $this->wheres[] = WhereCondition::compare('OR', $column, strtoupper($operator), $paramName);
        $this->params[$paramName] = $value instanceof \BackedEnum ? $value->value : $value;
        return $this;
    }

    public function whereNull(string $column): static
    {
        $this->wheres[] = WhereCondition::null($column, 'IS NULL');
        return $this;
    }

    public function whereNotNull(string $column): static
    {
        $this->wheres[] = WhereCondition::null($column, 'IS NOT NULL');
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
        $this->wheres[] = WhereCondition::in($column, 'IN', $paramNames);
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
        $this->wheres[] = WhereCondition::in($column, 'NOT IN', $paramNames);
        return $this;
    }

    public function whereLike(string $column, string $pattern): static
    {
        $paramName = $this->nextParam($column);
        $this->wheres[] = WhereCondition::compare('AND', $column, 'LIKE', $paramName);
        $this->params[$paramName] = $pattern;
        return $this;
    }

    public function whereBetween(string $column, mixed $from, mixed $to): static
    {
        $paramFrom = $this->nextParam($column . '_from');
        $paramTo   = $this->nextParam($column . '_to');
        $this->wheres[] = WhereCondition::between($column, $paramFrom, $paramTo);
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
        $this->wheres[] = WhereCondition::raw($sql);
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
            if ($i > 0 && $where->connector === 'OR') {
                $this->wheres = [WhereCondition::raw(substr($this->buildWhereClause(), strlen(' WHERE ')))];

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
            $parts[] = ($i === 0 ? '' : " {$where->connector} ") . $where->render();
        }

        return ' WHERE ' . implode('', $parts);
    }

    private function nextParam(string $column): string
    {
        $this->paramCounter++;
        $clean = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
        return "p_{$clean}_{$this->paramCounter}";
    }
}

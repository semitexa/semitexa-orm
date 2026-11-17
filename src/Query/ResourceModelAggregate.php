<?php

declare(strict_types=1);

namespace Semitexa\Orm\Query;

use Semitexa\Core\Support\Row;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\SqlIdentifier;
use Semitexa\Orm\Metadata\ColumnRef;

/**
 * Grouped and distinct reads over the rows a query matches, from
 * {@see ResourceModelQuery::aggregate()}. They see the query's WHERE, tenant
 * and soft-delete state as it stood when aggregate() was called, never its
 * ORDER BY, LIMIT or OFFSET. One table, standard SQL; the reads a store wrote
 * as raw SQL before (orm-gap-5-expression-order-group). As with countBy(), a
 * SQL NULL group comes back under the '' key.
 */
final class ResourceModelAggregate
{
    /**
     * Built by {@see ResourceModelQuery::aggregate()}, not by hand.
     *
     * @param class-string $resourceModelClass
     * @param array<string, mixed> $params
     */
    public function __construct(
        private readonly DatabaseAdapterInterface $adapter,
        private readonly string $resourceModelClass,
        private readonly string $tableName,
        private readonly string $whereSql,
        private readonly array $params,
    ) {}

    /**
     * SUM of one column per group, `['north' => 120, 'south' => 45]`: the
     * biggest first, ties by group, the order countBy() keeps. 0 for a group
     * whose values are all NULL.
     *
     * @return array<int|string, int|float>
     *
     * @api
     */
    public function sumBy(ColumnRef $group, ColumnRef $value): array
    {
        $out = [];
        foreach ($this->grouped('SUM', $group, $value) as $key => $sum) {
            $out[$key] = match (true) {
                $sum === null => 0,
                is_int($sum), is_string($sum) && preg_match('/^-?\d+$/', $sum) === 1 => Row::asInt($sum),
                default => Row::asFloat($sum),
            };
        }

        return $out;
    }

    /**
     * MAX of one column per group, the biggest first, ties by group. Each value
     * is the driver's raw one (string/int), as max() returns it; null for a
     * group whose values are all NULL.
     *
     * @return array<int|string, mixed>
     *
     * @api
     */
    public function maxBy(ColumnRef $group, ColumnRef $value): array
    {
        return $this->grouped('MAX', $group, $value);
    }

    /**
     * The distinct values of one column, ascending, as the driver returns them;
     * NULL, when a row has it, is last on every driver.
     *
     * @return list<mixed>
     *
     * @api
     */
    public function distinctValues(ColumnRef $column): array
    {
        $this->assertOwnColumn($column);
        $quoted = SqlIdentifier::quote($column->columnName);
        // The NULL flag is selected, and the order names only selected aliases:
        // with DISTINCT, PostgreSQL refuses an ORDER BY expression that is not
        // in the select list. It is a function of the column, so the pairs are
        // exactly the distinct values.
        $sql = sprintf(
            'SELECT DISTINCT %1$s AS __v, CASE WHEN %1$s IS NULL THEN 1 ELSE 0 END AS __n FROM %2$s%3$s ORDER BY __n ASC, __v ASC',
            $quoted,
            SqlIdentifier::quote($this->tableName),
            $this->whereSql,
        );

        $values = [];
        foreach ($this->adapter->execute($sql, $this->params)->rows as $row) {
            $values[] = $row['__v'] ?? null;
        }

        return $values;
    }

    /** @return array<int|string, mixed> group => the aggregate, biggest first, ties by group */
    private function grouped(string $function, ColumnRef $group, ColumnRef $value): array
    {
        $this->assertOwnColumn($group);
        $this->assertOwnColumn($value);
        $quotedGroup = SqlIdentifier::quote($group->columnName);
        $sql = sprintf(
            'SELECT %1$s AS __g, %2$s(%3$s) AS __a FROM %4$s%5$s GROUP BY %1$s ORDER BY __a DESC, %1$s ASC',
            $quotedGroup,
            $function,
            SqlIdentifier::quote($value->columnName),
            SqlIdentifier::quote($this->tableName),
            $this->whereSql,
        );

        $out = [];
        foreach ($this->adapter->execute($sql, $this->params)->rows as $row) {
            $out[Row::of($row)->string('__g')] = $row['__a'] ?? null;
        }

        return $out;
    }

    private function assertOwnColumn(ColumnRef $column): void
    {
        if ($column->resourceModelClass !== $this->resourceModelClass) {
            throw new \InvalidArgumentException(sprintf(
                'ColumnRef for %s cannot be used in an aggregate of %s.',
                $column->resourceModelClass,
                $this->resourceModelClass,
            ));
        }
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Orm\Query;

/**
 * The SQL of {@see ResourceModelQuery::orderByValues()}: a column ordered by
 * where its value stands in a list. Each place is a value, or a list of values
 * that share it; a row whose value is listed nowhere comes after every place.
 *
 *   CASE WHEN "status" IN (:w0) THEN 0 WHEN "status" IN (:w1, :w2) THEN 1 ELSE 2 END
 *
 * Standard SQL on every driver; the values are bound like a where's, never
 * written into the statement (orm-gap-5-expression-order-group).
 *
 * @internal
 */
final class ValueRankOrdering
{
    /**
     * The places, each a non-empty list of values. Refuses an empty list, an
     * empty place and a null value: CASE … WHEN NULL never matches, so a null
     * would silently rank nothing.
     *
     * @param array<mixed> $values
     * @return list<list<mixed>>
     */
    public static function places(array $values): array
    {
        if ($values === []) {
            throw new \InvalidArgumentException('orderByValues() needs at least one value to order by.');
        }
        $places = [];
        foreach (array_values($values) as $place) {
            $members = is_array($place) ? array_values($place) : [$place];
            if ($members === []) {
                throw new \InvalidArgumentException('orderByValues(): a place is an empty list; give it at least one value.');
            }
            foreach ($members as $member) {
                if ($member === null) {
                    throw new \InvalidArgumentException('orderByValues() cannot rank NULL (CASE never matches it); order nulls with orderBy() or leave them unlisted, they come last.');
                }
                if (is_array($member)) {
                    throw new \InvalidArgumentException('orderByValues(): a place is a value or a list of values, not a deeper list.');
                }
            }
            $places[] = $members;
        }

        return $places;
    }

    /**
     * @param list<array<string, mixed>> $bound per place, param name => value
     */
    public static function sql(string $quotedColumn, array $bound): string
    {
        $whens = [];
        foreach ($bound as $rank => $params) {
            $whens[] = sprintf(
                'WHEN %s IN (%s) THEN %d',
                $quotedColumn,
                implode(', ', array_map(static fn (string $param): string => ':' . $param, array_keys($params))),
                $rank,
            );
        }

        return sprintf('CASE %s ELSE %d END', implode(' ', $whens), count($bound));
    }
}

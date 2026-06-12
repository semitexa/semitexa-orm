<?php

declare(strict_types=1);

namespace Semitexa\Orm\Query;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Contract\CollectionQueryCompilerInterface;
use Semitexa\Core\Resource\CollectionCriteria;
use Semitexa\Core\Resource\CollectionPaginationPolicy;
use Semitexa\Core\Resource\CompiledCollection;
use Semitexa\Core\Resource\Cursor\CollectionCursor;
use Semitexa\Core\Resource\Cursor\CollectionCursorCodec;
use Semitexa\Core\Resource\Cursor\CollectionCursorPage;
use Semitexa\Core\Resource\Exception\InvalidPaginationException;
use Semitexa\Core\Resource\Filter\FilterOperator;
use Semitexa\Core\Resource\Pagination\CollectionPage;
use Semitexa\Core\Resource\Sort\CollectionSortRequest;
use Semitexa\Core\Resource\Sort\SortDirection;
use Semitexa\Core\Resource\Sort\SortTerm;
use Semitexa\Orm\Metadata\ColumnRef;

/**
 * One Way Phase 2: the ORM implementation of the criteria push-down
 * seam — compiles a validated {@see CollectionCriteria} onto a
 * {@see ResourceModelQuery} so WHERE / ORDER BY / LIMIT happen at SQL
 * level, never in-memory:
 *
 *   - filter `eq`       → `WHERE col = ?`
 *   - filter `in`       → `WHERE col IN (?, …)`
 *   - filter `contains` → `WHERE col LIKE ?` (escaped, %-wrapped)
 *   - search `q`        → `WHERE (a LIKE ? OR b LIKE ?)` — the single
 *                         OR-group ({@see ResourceModelQuery::whereAnyLike()})
 *   - sort terms        → `ORDER BY` (+ a deterministic `id` tie-breaker
 *                         appended when absent, so pages never shuffle)
 *   - page / cursor     → `LIMIT` / `OFFSET`, or a keyset predicate for
 *                         cursor continuation
 *
 * Mode policy ({@see CollectionPaginationPolicy}): an explicit
 * `?cursor=` always answers in cursor mode; `auto` counts the filtered
 * set and answers page mode within `countThreshold`, else cursor mode
 * (an explicit `?page=` over the threshold is a typed 400 — the server
 * cannot honor offset windows it refuses to count-paginate).
 *
 * Cursor continuation note: the keyset predicate
 * (`(s1 < v1) OR (s1 = v1 AND id > v2) …`) needs per-branch AND/OR
 * nesting that the deliberately-flat OR-group does not model; it is
 * built through the EXISTING `whereRaw()` escape hatch with bound
 * parameters and metadata-derived (never user-supplied) column names.
 * No new ORM grouping capability is involved.
 *
 * The caller's query is never mutated — all work happens on clones, so
 * a handler can reuse its base query.
 */
#[AsService]
#[SatisfiesServiceContract(of: CollectionQueryCompilerInterface::class)]
final class CollectionQueryCompiler implements CollectionQueryCompilerInterface
{
    public function supports(object $source): bool
    {
        return $source instanceof ResourceModelQuery;
    }

    /**
     * @param array<string, string> $fieldMap criteria field → resource-model property
     */
    public function compile(CollectionCriteria $criteria, object $source, array $fieldMap = []): CompiledCollection
    {
        if (!$source instanceof ResourceModelQuery) {
            throw new \InvalidArgumentException(sprintf(
                '%s supports ResourceModelQuery sources only, got %s.',
                self::class,
                get_debug_type($source),
            ));
        }

        $modelClass = $source->resourceModelClass();
        $filtered = clone $source;

        foreach ($criteria->filter->terms as $term) {
            $column = $this->columnFor($modelClass, $term->field, $fieldMap);
            match ($term->operator) {
                FilterOperator::Eq       => $filtered->where($column, Operator::Equals, $term->value),
                FilterOperator::In       => $filtered->whereIn($column, (array) $term->value),
                FilterOperator::Contains => $filtered->whereLike(
                    $column,
                    '%' . self::escapeLikePattern((string) $term->value) . '%',
                ),
            };
        }

        if ($criteria->hasSearch()) {
            $columns = [];
            foreach ($criteria->searchFields as $field) {
                $columns[] = $this->columnFor($modelClass, $field, $fieldMap);
            }
            $filtered->whereAnyLike($columns, '%' . self::escapeLikePattern((string) $criteria->q) . '%');
        }

        $mode = $this->resolveMode($criteria, $filtered);

        return match ($mode) {
            CollectionPaginationPolicy::MODE_PAGE   => $this->executePage($criteria, $filtered, $modelClass, $fieldMap),
            CollectionPaginationPolicy::MODE_CURSOR => $this->executeCursor($criteria, $filtered, $modelClass, $fieldMap),
            CollectionPaginationPolicy::MODE_SINGLE => $this->executeSingle($criteria, $filtered, $modelClass, $fieldMap),
            default => throw new \LogicException('Unreachable pagination mode: ' . $mode),
        };
    }

    // ------------------------------------------------------------------
    // Mode policy
    // ------------------------------------------------------------------

    private function resolveMode(CollectionCriteria $criteria, ResourceModelQuery $filtered): string
    {
        if ($criteria->isCursorRequest()) {
            return CollectionPaginationPolicy::MODE_CURSOR;
        }

        $policy = $criteria->policy;
        if ($policy->mode !== CollectionPaginationPolicy::MODE_AUTO) {
            return $policy->mode;
        }

        $total = (clone $filtered)->count();
        if ($total <= $policy->countThreshold) {
            return CollectionPaginationPolicy::MODE_PAGE;
        }
        if ($criteria->pageWasRequested) {
            throw new InvalidPaginationException(
                'page',
                (string) $criteria->page->page,
                sprintf(
                    'page mode is unavailable: the collection total (%d) exceeds the route countThreshold (%d) — use cursor pagination',
                    $total,
                    $policy->countThreshold,
                ),
            );
        }

        return CollectionPaginationPolicy::MODE_CURSOR;
    }

    // ------------------------------------------------------------------
    // Execution strategies
    // ------------------------------------------------------------------

    /** @param array<string, string> $fieldMap */
    private function executePage(
        CollectionCriteria $criteria,
        ResourceModelQuery $filtered,
        string $modelClass,
        array $fieldMap,
    ): CompiledCollection {
        $total = (clone $filtered)->count();

        $windowed = clone $filtered;
        foreach ($this->effectiveSortTerms($criteria->sort) as $term) {
            $windowed->orderBy(
                $this->columnFor($modelClass, $term->field, $fieldMap),
                $term->direction === SortDirection::Desc ? Direction::Desc : Direction::Asc,
            );
        }
        $windowed->limit($criteria->page->perPage)->offset($criteria->page->offset());

        return new CompiledCollection(
            items: $windowed->fetchAll(),
            page:  CollectionPage::compute(
                $criteria->page,
                $total,
                $criteria->policy->declared ? CollectionPaginationPolicy::MODE_PAGE : null,
            ),
        );
    }

    /** @param array<string, string> $fieldMap */
    private function executeSingle(
        CollectionCriteria $criteria,
        ResourceModelQuery $filtered,
        string $modelClass,
        array $fieldMap,
    ): CompiledCollection {
        $windowed = clone $filtered;
        foreach ($this->effectiveSortTerms($criteria->sort) as $term) {
            $windowed->orderBy(
                $this->columnFor($modelClass, $term->field, $fieldMap),
                $term->direction === SortDirection::Desc ? Direction::Desc : Direction::Asc,
            );
        }
        $items = $windowed->fetchAll();
        $total = count($items);

        return new CompiledCollection(
            items: $items,
            page:  new CollectionPage(
                page:        1,
                perPage:     max(1, $total),
                total:       $total,
                pageCount:   $total > 0 ? 1 : 0,
                hasNext:     false,
                hasPrevious: false,
                mode:        CollectionPaginationPolicy::MODE_SINGLE,
            ),
        );
    }

    /** @param array<string, string> $fieldMap */
    private function executeCursor(
        CollectionCriteria $criteria,
        ResourceModelQuery $filtered,
        string $modelClass,
        array $fieldMap,
    ): CompiledCollection {
        $perPage = $criteria->page->perPage;
        $effectiveTerms = $this->effectiveSortTerms($criteria->sort);

        // Post-filter total, counted BEFORE the keyset predicate narrows
        // the window (the cursor envelope documents "post-filter total").
        $total = (clone $filtered)->count();

        $windowed = clone $filtered;

        if ($criteria->cursor !== null) {
            $codec  = new CollectionCursorCodec();
            $cursor = $codec->decode(
                $criteria->cursor,
                $criteria->sort->toQueryString(),
                $criteria->filter->toQueryString(),
            );
            $this->applyKeysetPredicate($windowed, $modelClass, $fieldMap, $effectiveTerms, $cursor);
        }

        foreach ($effectiveTerms as $term) {
            $windowed->orderBy(
                $this->columnFor($modelClass, $term->field, $fieldMap),
                $term->direction === SortDirection::Desc ? Direction::Desc : Direction::Asc,
            );
        }

        // Peek-ahead: fetch one row beyond the window to decide hasNext
        // without a second query.
        $rows    = $windowed->limit($perPage + 1)->fetchAll();
        $hasNext = count($rows) > $perPage;
        $items   = array_slice($rows, 0, $perPage);

        $nextCursor = null;
        if ($hasNext && $items !== []) {
            $last  = $items[array_key_last($items)];
            $codec = new CollectionCursorCodec();
            $nextCursor = $codec->encode(new CollectionCursor(
                version:         CollectionCursor::CURRENT_VERSION,
                sortSignature:   $criteria->sort->toQueryString(),
                filterSignature: $criteria->filter->toQueryString(),
                lastSortKey:     $this->sortKeyValuesFor($last, $criteria->sort, $fieldMap),
                lastId:          $this->stringifyValue($this->propertyValue($last, $fieldMap['id'] ?? 'id')),
            ));
        }

        return new CompiledCollection(
            items: $items,
            cursorPage: new CollectionCursorPage(
                perPage:    $perPage,
                total:      $total,
                hasNext:    $hasNext,
                nextCursor: $nextCursor,
                cursor:     $criteria->cursor,
            ),
        );
    }

    // ------------------------------------------------------------------
    // Keyset continuation
    // ------------------------------------------------------------------

    /**
     * Standard keyset expansion over the effective sort terms
     * (user sort + `id` tie-breaker): a row comes after the cursor row
     * when the first differing term says so —
     *
     *     (t1 OP1 v1) OR (t1 = v1 AND t2 OP2 v2) OR …
     *
     * Column names come from ORM metadata ({@see ColumnRef}), values are
     * bound parameters; nothing user-supplied reaches the SQL string.
     *
     * @param array<string, string> $fieldMap
     * @param list<SortTerm>        $effectiveTerms
     */
    private function applyKeysetPredicate(
        ResourceModelQuery $query,
        string $modelClass,
        array $fieldMap,
        array $effectiveTerms,
        CollectionCursor $cursor,
    ): void {
        $cursorValues = [...$cursor->lastSortKey, $cursor->lastId];

        $branches = [];
        $bindings = [];
        foreach ($effectiveTerms as $i => $term) {
            $parts = [];
            for ($j = 0; $j < $i; $j++) {
                $parts[] = sprintf('`%s` = ?', $this->columnFor($modelClass, $effectiveTerms[$j]->field, $fieldMap)->columnName);
                $bindings[] = $cursorValues[$j] ?? '';
            }
            $parts[] = sprintf(
                '`%s` %s ?',
                $this->columnFor($modelClass, $term->field, $fieldMap)->columnName,
                $term->direction === SortDirection::Desc ? '<' : '>',
            );
            $bindings[] = $cursorValues[$i] ?? '';

            $branches[] = count($parts) > 1 ? '(' . implode(' AND ', $parts) . ')' : $parts[0];
        }

        $query->whereRaw(implode(' OR ', $branches), $bindings);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * User sort terms plus a deterministic `id ASC` tie-breaker when the
     * user sort does not already pin `id` — SQL result order is undefined
     * without it, and both offset pages and keyset cursors need a unique
     * total order. The tie-breaker never appears in the cursor's sort
     * signature (same rule as the in-memory customers pipeline).
     *
     * @return list<SortTerm>
     */
    private function effectiveSortTerms(CollectionSortRequest $sort): array
    {
        $terms = $sort->terms;
        foreach ($terms as $term) {
            if ($term->field === 'id') {
                return $terms;
            }
        }
        $terms[] = new SortTerm('id', SortDirection::Asc);

        return $terms;
    }

    /** @param array<string, string> $fieldMap */
    private function columnFor(string $modelClass, string $field, array $fieldMap): ColumnRef
    {
        return ColumnRef::for($modelClass, $fieldMap[$field] ?? $field);
    }

    /**
     * @param array<string, string> $fieldMap
     * @return list<string>
     */
    private function sortKeyValuesFor(object $item, CollectionSortRequest $userSort, array $fieldMap): array
    {
        $out = [];
        foreach ($userSort->terms as $term) {
            $out[] = $this->stringifyValue($this->propertyValue($item, $fieldMap[$term->field] ?? $term->field));
        }

        return $out;
    }

    private function propertyValue(object $item, string $property): mixed
    {
        return $item->{$property} ?? null;
    }

    /**
     * Canonical string form for cursor keys — MUST be stable AND compare
     * the same way SQL compares the underlying column (datetimes use the
     * column's storage format; numbers coerce numerically on the server).
     */
    private function stringifyValue(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Escape LIKE wildcards in user-supplied terms so they match
     * literally (MySQL default escape character `\`). The pattern is
     * still bound as a parameter — this only neutralizes `%`/`_`.
     */
    private static function escapeLikePattern(string $value): string
    {
        return addcslashes($value, '\\%_');
    }
}

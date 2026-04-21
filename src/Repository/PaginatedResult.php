<?php

declare(strict_types=1);

namespace Semitexa\Orm\Repository;

/**
 * Immutable result of a paginated query.
 *
 * @template TItem of object
 */
readonly class PaginatedResult
{
    public int $lastPage;

    /**
     * @param list<TItem> $items
     * @param int $total Total matching records across all pages
     * @param int $page Current page (1-based)
     * @param int $perPage Items per page
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {
        // lastPage is always >= 1 so UIs and templates can render a page
        // number even when the result set is empty.
        if ($perPage <= 0) {
            $this->lastPage = 1;
        } else {
            $this->lastPage = (int) max(1, ceil($total / $perPage));
        }
    }

    public function hasNextPage(): bool
    {
        return $this->page < $this->lastPage;
    }

    public function hasPreviousPage(): bool
    {
        return $this->page > 1;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Apply a transformation to every item, preserving pagination metadata.
     *
     * @template TOut of object
     * @param callable(TItem): TOut $mapper
     * @return self<TOut>
     */
    public function map(callable $mapper): self
    {
        /** @var list<TItem> $items */
        $items = $this->items;

        /** @var list<TOut> $mapped */
        $mapped = array_map($mapper, $items);

        return new self(
            items: $mapped,
            total: $this->total,
            page: $this->page,
            perPage: $this->perPage,
        );
    }
}

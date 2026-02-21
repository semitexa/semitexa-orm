<?php

declare(strict_types=1);

namespace Semitexa\Orm\Repository;

readonly class PaginatedResult
{
    public int $lastPage;

    /**
     * @param object[] $items Domain objects
     * @param int $total Total matching records
     * @param int $page Current page (1-based)
     * @param int $perPage Items per page
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {
        $this->lastPage = $perPage > 0 ? (int) ceil($total / $perPage) : 1;
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
}

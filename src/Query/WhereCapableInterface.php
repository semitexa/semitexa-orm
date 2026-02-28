<?php

declare(strict_types=1);

namespace Semitexa\Orm\Query;

/**
 * Marks a query builder as capable of accepting WHERE conditions.
 *
 * Implemented by SelectQuery, UpdateQuery, and DeleteQuery (all use WhereTrait).
 * TenantScopeInterface::apply() receives `object $queryBuilder`; implementations
 * that need to add WHERE clauses should type-check against this interface and
 * throw \InvalidArgumentException when an unsupported query type is passed.
 */
interface WhereCapableInterface
{
    public function where(string $column, string $operator, mixed $value): static;

    public function whereNull(string $column): static;

    public function whereIn(string $column, array $values): static;
}

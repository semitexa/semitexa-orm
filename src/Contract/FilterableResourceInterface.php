<?php

declare(strict_types=1);

namespace Semitexa\Orm\Contract;

/**
 * Resource that can supply filter criteria for Repository::find().
 * Use FilterableTrait to implement filterByX(), getFilterCriteria(),
 * filterBy{Relation}{Column}(), and getRelationFilterCriteria().
 */
interface FilterableResourceInterface
{
    /**
     * @return array<string, mixed> DB column name => value for WHERE clause on main table
     */
    public function getFilterCriteria(): array;

    /**
     * Relation filter criteria: relation property name => [DB column name => value] on related table.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getRelationFilterCriteria(): array;
}

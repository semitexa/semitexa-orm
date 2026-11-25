<?php

declare(strict_types=1);

namespace Semitexa\Orm\Domain\Contract;

/**
 * Resource that can supply filter criteria for Repository::find().
 * Use FilterableTrait to implement filterByX(), getFilterCriteria(),
 * filterBy{Relation}{Column}(), and getRelationFilterCriteria().
 *
 * @deprecated No built-in repository reads these criteria: none implements
 *             RepositoryInterface::find(), and a final readonly resource model
 *             cannot use FilterableTrait. Filter with DomainRepository::findBy() criteria or
 *             query()->where(). It will be removed in a later release.
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

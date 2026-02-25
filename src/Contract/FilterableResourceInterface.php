<?php

declare(strict_types=1);

namespace Semitexa\Orm\Contract;

/**
 * Resource that can supply filter criteria for Repository::find().
 * Use FilterableTrait to implement filterByX() and getFilterCriteria().
 */
interface FilterableResourceInterface
{
    /**
     * @return array<string, mixed> DB column name => value for WHERE clause
     */
    public function getFilterCriteria(): array;
}

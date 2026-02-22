<?php

declare(strict_types=1);

namespace Semitexa\Orm\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class Aggregate
{
    /**
     * @param string $function SQL aggregate function (COUNT, SUM, AVG, MIN, MAX)
     * @param string $field Related table and column in format "table.column"
     * @param string|null $foreignKey FK column in the related table pointing to parent PK.
     *                                If null, convention "{parent_table_singular}_id" is used.
     */
    public function __construct(
        public string $function,
        public string $field,
        public ?string $foreignKey = null,
    ) {}
}

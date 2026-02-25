<?php

declare(strict_types=1);

namespace Semitexa\Orm\Attribute;

use Attribute;

/**
 * Marks a property as usable in filter conditions via filterByX().
 * A DB index is automatically created for this column.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class Filterable
{
    public function __construct(
        /** Override the DB column name when it differs from the property name. */
        public ?string $name = null,
    ) {}
}

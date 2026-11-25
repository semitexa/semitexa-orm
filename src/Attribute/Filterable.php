<?php

declare(strict_types=1);

namespace Semitexa\Orm\Attribute;

use Attribute;

/**
 * Adds a single-column, non-unique index on this property's column. To
 * filter by it, pass criteria to DomainRepository::findBy() or use
 * query()->where(): the deprecated FilterableTrait's filterByX() methods cannot
 * be used by a final readonly resource model.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class Filterable
{
    public function __construct(
        /** Override the DB column name when it differs from the property name. */
        public ?string $name = null,
    ) {}
}

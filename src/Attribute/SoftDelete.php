<?php

declare(strict_types=1);

namespace Semitexa\Orm\Attribute;

use Attribute;

/** @api */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class SoftDelete
{
    public function __construct(
        public string $column,
    ) {}
}

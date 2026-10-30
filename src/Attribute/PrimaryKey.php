<?php

declare(strict_types=1);

namespace Semitexa\Orm\Attribute;

use Attribute;

/** @api */
#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class PrimaryKey
{
    public function __construct(
        public string $strategy = 'auto',
    ) {}
}

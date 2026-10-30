<?php

declare(strict_types=1);

namespace Semitexa\Orm\Attribute;

use Attribute;

/** @api */
#[Attribute(Attribute::TARGET_CLASS)]
readonly class Connection
{
    public function __construct(
        public string $name = 'default',
    ) {}
}

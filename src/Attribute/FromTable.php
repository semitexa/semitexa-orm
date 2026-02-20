<?php

declare(strict_types=1);

namespace Semitexa\Orm\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
readonly class FromTable
{
    public function __construct(
        public string $name,
        public ?string $mapTo = null,
    ) {}
}

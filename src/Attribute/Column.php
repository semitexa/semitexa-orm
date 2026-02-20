<?php

declare(strict_types=1);

namespace Semitexa\Orm\Attribute;

use Attribute;
use Semitexa\Orm\Adapter\MySqlType;

#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class Column
{
    public function __construct(
        public MySqlType $type,
        public ?int $length = null,
        public ?int $precision = null,
        public ?int $scale = null,
        public mixed $default = null,
        public bool $nullable = false,
    ) {}
}

<?php

declare(strict_types=1);

namespace Semitexa\Orm\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
readonly class Index
{
    public array $columns;

    public function __construct(
        string|array $columns,
        public bool $unique = false,
        public ?string $name = null,
    ) {
        $this->columns = is_string($columns) ? [$columns] : $columns;
    }
}

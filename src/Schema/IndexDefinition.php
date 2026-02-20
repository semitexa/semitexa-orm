<?php

declare(strict_types=1);

namespace Semitexa\Orm\Schema;

readonly class IndexDefinition
{
    public function __construct(
        public array $columns,
        public bool $unique = false,
        public ?string $name = null,
    ) {}
}

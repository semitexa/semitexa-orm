<?php

declare(strict_types=1);

namespace Semitexa\Orm\Domain\Model;

readonly class IndexDefinition
{
    /**
     * @param array<string> $columns
     */
    public function __construct(
        public array $columns,
        public bool $unique = false,
        public ?string $name = null,
    ) {}
}

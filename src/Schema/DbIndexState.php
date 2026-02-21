<?php

declare(strict_types=1);

namespace Semitexa\Orm\Schema;

readonly class DbIndexState
{
    public function __construct(
        public string $name,
        /** @var string[] */
        public array $columns,
        public bool $unique,
    ) {}
}

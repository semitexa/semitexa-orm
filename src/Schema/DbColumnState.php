<?php

declare(strict_types=1);

namespace Semitexa\Orm\Schema;

readonly class DbColumnState
{
    public function __construct(
        public string $name,
        public string $dataType,
        public string $columnType,
        public bool $nullable,
        public ?string $defaultValue,
        public bool $isPrimaryKey,
        public bool $isAutoIncrement,
        public ?int $maxLength,
        public ?int $numericPrecision,
        public ?int $numericScale,
        public string $comment = '',
    ) {}
}

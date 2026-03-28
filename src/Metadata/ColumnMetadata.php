<?php

declare(strict_types=1);

namespace Semitexa\Orm\Metadata;

use Semitexa\Orm\Adapter\MySqlType;

final readonly class ColumnMetadata
{
    public function __construct(
        public string $propertyName,
        public string $columnName,
        public MySqlType $type,
        public string $phpType,
        public bool $nullable,
        public ?int $length = null,
        public ?int $precision = null,
        public ?int $scale = null,
        public mixed $default = null,
        public bool $isPrimaryKey = false,
        public ?string $primaryKeyStrategy = null,
    ) {}
}

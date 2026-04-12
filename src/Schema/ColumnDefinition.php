<?php

declare(strict_types=1);

namespace Semitexa\Orm\Schema;

use Semitexa\Orm\Adapter\DatabaseType;

readonly class ColumnDefinition
{
    public function __construct(
        /** DB column name */
        public string $name,
        public DatabaseType $type,
        public string $phpType,
        public bool $nullable = false,
        public ?int $length = null,
        public ?int $precision = null,
        public ?int $scale = null,
        public mixed $default = null,
        public bool $isPrimaryKey = false,
        public string $pkStrategy = 'auto',
        public bool $isDeprecated = false,
        /** PHP property name — equals $name when no explicit mapping */
        public string $propertyName = '',
    ) {}
}

<?php

declare(strict_types=1);

namespace Semitexa\Orm\Metadata;

final readonly class SoftDeleteMetadata
{
    public function __construct(
        public string $propertyName,
        public string $columnName,
    ) {}
}

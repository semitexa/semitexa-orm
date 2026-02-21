<?php

declare(strict_types=1);

namespace Semitexa\Orm\Hydration;

readonly class RelationMeta
{
    public function __construct(
        public string $property,
        public string $type,
        public string $targetClass,
        public string $foreignKey,
        public ?string $pivotTable = null,
        public ?string $relatedKey = null,
    ) {}
}

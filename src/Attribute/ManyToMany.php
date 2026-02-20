<?php

declare(strict_types=1);

namespace Semitexa\Orm\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class ManyToMany
{
    public function __construct(
        public string $target,
        public string $pivotTable,
        public string $foreignKey,
        public string $relatedKey,
    ) {}
}

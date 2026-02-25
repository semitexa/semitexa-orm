<?php

declare(strict_types=1);

namespace Semitexa\Orm\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class BelongsTo
{
    public function __construct(
        public string $target,
        public string $foreignKey,
        public ?\Semitexa\Orm\Schema\ForeignKeyAction $onDelete = null,
        public ?\Semitexa\Orm\Schema\ForeignKeyAction $onUpdate = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace Semitexa\Orm\Attribute;

use Attribute;
use Semitexa\Orm\Domain\Enum\RelationWritePolicy;

#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class HasMany
{
    public function __construct(
        public string $target,
        public string $foreignKey,
        public ?\Semitexa\Orm\Domain\Enum\ForeignKeyAction $onDelete = null,
        public ?\Semitexa\Orm\Domain\Enum\ForeignKeyAction $onUpdate = null,
        public ?RelationWritePolicy $writePolicy = null,
    ) {}
}

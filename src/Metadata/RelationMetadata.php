<?php

declare(strict_types=1);

namespace Semitexa\Orm\Metadata;

use Semitexa\Orm\Domain\Enum\RelationWritePolicy;
use Semitexa\Orm\Domain\Enum\ForeignKeyAction;

final readonly class RelationMetadata
{
    /**
     * @param class-string $targetClass
     */
    public function __construct(
        public string $propertyName,
        public RelationKind $kind,
        public string $targetClass,
        public string $foreignKey,
        public ?string $pivotTable = null,
        public ?string $relatedKey = null,
        public ?ForeignKeyAction $onDelete = null,
        public ?ForeignKeyAction $onUpdate = null,
        public ?RelationWritePolicy $writePolicy = null,
    ) {}
}

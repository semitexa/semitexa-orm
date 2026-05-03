<?php

declare(strict_types=1);

namespace Semitexa\Orm\Domain\Model;

use Semitexa\Orm\Domain\Enum\RelationType;

readonly class RelationMeta
{
    public function __construct(
        public string       $property,
        public RelationType $type,
        public string       $targetClass,
        public string       $foreignKey,
        public ?string      $pivotTable = null,
        public ?string      $relatedKey = null,
    ) {}
}

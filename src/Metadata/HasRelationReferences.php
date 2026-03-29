<?php

declare(strict_types=1);

namespace Semitexa\Orm\Metadata;

trait HasRelationReferences
{
    public static function relation(string $propertyName): RelationRef
    {
        /** @var class-string $tableModelClass */
        $tableModelClass = static::class;

        return RelationRef::for($tableModelClass, $propertyName);
    }
}

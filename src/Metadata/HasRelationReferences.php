<?php

declare(strict_types=1);

namespace Semitexa\Orm\Metadata;

/** @api */
trait HasRelationReferences
{
    public static function relation(string $propertyName): RelationRef
    {
        /** @var class-string $resourceModelClass */
        $resourceModelClass = static::class;

        return RelationRef::for($resourceModelClass, $propertyName);
    }
}

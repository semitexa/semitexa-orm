<?php

declare(strict_types=1);

namespace Semitexa\Orm\Metadata;

trait HasColumnReferences
{
    public static function column(string $propertyName): ColumnRef
    {
        /** @var class-string $resourceModelClass */
        $resourceModelClass = static::class;

        return ColumnRef::for($resourceModelClass, $propertyName);
    }
}

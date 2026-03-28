<?php

declare(strict_types=1);

namespace Semitexa\Orm\Metadata;

trait HasColumnReferences
{
    public static function column(string $propertyName): ColumnRef
    {
        /** @var class-string $tableModelClass */
        $tableModelClass = static::class;

        return ColumnRef::for($tableModelClass, $propertyName);
    }
}

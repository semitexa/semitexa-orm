<?php

declare(strict_types=1);

namespace Semitexa\Orm\Metadata;

final readonly class ColumnRef
{
    public function __construct(
        public string $tableModelClass,
        public string $propertyName,
        public string $columnName,
    ) {}

    /**
     * @param class-string $tableModelClass
     */
    public static function for(string $tableModelClass, string $propertyName): self
    {
        $metadata = TableModelMetadataRegistry::default()->for($tableModelClass);
        $column = $metadata->column($propertyName);

        return new self(
            tableModelClass: $tableModelClass,
            propertyName: $column->propertyName,
            columnName: $column->columnName,
        );
    }
}

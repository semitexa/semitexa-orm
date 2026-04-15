<?php

declare(strict_types=1);

namespace Semitexa\Orm\Metadata;

final readonly class ColumnRef
{
    /**
     * @param class-string $resourceModelClass
     */
    public function __construct(
        public string $resourceModelClass,
        public string $propertyName,
        public string $columnName,
    ) {}

    /**
     * @param class-string $resourceModelClass
     */
    public static function for(string $resourceModelClass, string $propertyName): self
    {
        $metadata = ResourceModelMetadataRegistry::default()->for($resourceModelClass);
        $column = $metadata->column($propertyName);

        return new self(
            resourceModelClass: $resourceModelClass,
            propertyName: $column->propertyName,
            columnName: $column->columnName,
        );
    }
}

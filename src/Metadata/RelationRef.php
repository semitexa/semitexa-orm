<?php

declare(strict_types=1);

namespace Semitexa\Orm\Metadata;

final readonly class RelationRef
{
    public function __construct(
        public string $tableModelClass,
        public string $propertyName,
    ) {}

    /**
     * @param class-string $tableModelClass
     */
    public static function for(string $tableModelClass, string $propertyName): self
    {
        $metadata = TableModelMetadataRegistry::default()->for($tableModelClass);
        $metadata->relation($propertyName);

        return new self(
            tableModelClass: $tableModelClass,
            propertyName: $propertyName,
        );
    }
}

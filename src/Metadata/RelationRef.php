<?php

declare(strict_types=1);

namespace Semitexa\Orm\Metadata;

final readonly class RelationRef
{
    /**
     * @param class-string $resourceModelClass
     */
    public function __construct(
        public string $resourceModelClass,
        public string $propertyName,
    ) {}

    /**
     * @param class-string $resourceModelClass
     */
    public static function for(string $resourceModelClass, string $propertyName): self
    {
        $metadata = ResourceModelMetadataRegistry::default()->for($resourceModelClass);
        $metadata->relation($propertyName);

        return new self(
            resourceModelClass: $resourceModelClass,
            propertyName: $propertyName,
        );
    }
}

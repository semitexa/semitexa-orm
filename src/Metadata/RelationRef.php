<?php

declare(strict_types=1);

namespace Semitexa\Orm\Metadata;

/**
 * A relation of a resource model, named by the metadata rather than by a caller.
 *
 * Private constructor for the reason given on [[ColumnRef]]: a relation's
 * foreignKey, relatedKey and pivotTable are interpolated into SQL by
 * ResourceModelRelationLoader, so the name has to come from the attributes.
 * Both factories validate the property against its metadata before building.
 */
final readonly class RelationRef
{
    /**
     * @param class-string $resourceModelClass
     */
    private function __construct(
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

    /**
     * A DOT-PATH relation reference for nested eager loading —
     * `RelationRef::path(Order::class, 'items.product')` loads `items` on the
     * orders, then batch-loads `product` on the loaded items. Every segment
     * is validated against its level's metadata (unknown segment throws), so
     * a typo fails at construction, not silently at load time.
     *
     * @param class-string $resourceModelClass
     */
    public static function path(string $resourceModelClass, string $dotPath): self
    {
        $registry = ResourceModelMetadataRegistry::default();
        $class = $resourceModelClass;
        foreach (explode('.', $dotPath) as $segment) {
            $relation = $registry->for($class)->relation($segment);
            $class = $relation->targetClass;
        }

        return new self(
            resourceModelClass: $resourceModelClass,
            propertyName: $dotPath,
        );
    }
}

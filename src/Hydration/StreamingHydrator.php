<?php

declare(strict_types=1);

namespace Semitexa\Orm\Hydration;

use Semitexa\Orm\Contract\DomainMappable;

class StreamingHydrator
{
    private Hydrator $hydrator;
    private ?RelationLoader $relationLoader;

    public function __construct(?Hydrator $hydrator = null, ?RelationLoader $relationLoader = null)
    {
        $this->hydrator = $hydrator ?? new Hydrator();
        $this->relationLoader = $relationLoader;
    }

    public function setRelationLoader(RelationLoader $relationLoader): void
    {
        $this->relationLoader = $relationLoader;
    }

    /**
     * Hydrate a single row into a Domain object via Resource→toDomain().
     * If RelationLoader is set, loads relations on the Resource before domain conversion.
     * Resource is not kept in memory after conversion.
     *
     * @param array<string, mixed> $row
     * @param class-string $resourceClass
     * @return object Domain object
     */
    public function hydrateToDomain(array $row, string $resourceClass): object
    {
        $resource = $this->hydrator->hydrate($row, $resourceClass);

        // Load relations for a single resource
        $this->relationLoader?->loadRelations([$resource], $resourceClass);

        if ($resource instanceof DomainMappable) {
            return $resource->toDomain();
        }

        return $resource;
    }

    /**
     * Hydrate multiple rows into Domain objects.
     * Batch-loads relations for all resources at once (avoids N+1).
     *
     * @param array<int, array<string, mixed>> $rows
     * @param class-string $resourceClass
     * @return object[]
     */
    public function hydrateAllToDomain(array $rows, string $resourceClass): array
    {
        if ($rows === []) {
            return [];
        }

        // Step 1: Hydrate all rows into Resources
        $resources = [];
        foreach ($rows as $row) {
            $resources[] = $this->hydrator->hydrate($row, $resourceClass);
        }

        // Step 2: Batch-load relations on all Resources (one query per relation type)
        $this->relationLoader?->loadRelations($resources, $resourceClass);

        // Step 3: Convert each Resource to Domain and discard Resource
        $result = [];
        foreach ($resources as $resource) {
            if ($resource instanceof DomainMappable) {
                $result[] = $resource->toDomain();
            } else {
                $result[] = $resource;
            }
        }

        return $result;
    }

    /**
     * Hydrate a single row into a Resource (without domain mapping).
     *
     * @template T of object
     * @param array<string, mixed> $row
     * @param class-string<T> $resourceClass
     * @return T
     */
    public function hydrateToResource(array $row, string $resourceClass): object
    {
        return $this->hydrator->hydrate($row, $resourceClass);
    }

    public function getHydrator(): Hydrator
    {
        return $this->hydrator;
    }
}

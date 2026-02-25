<?php

declare(strict_types=1);

namespace Semitexa\Orm\Factory;

/**
 * Generic implementation: creates a new instance of the given resource class on each create().
 */
final class ResourceFactory implements ResourceFactoryInterface
{
    /**
     * @param class-string $resourceClass
     */
    public function __construct(
        private readonly string $resourceClass,
    ) {}

    public function create(): object
    {
        return new ($this->resourceClass)();
    }
}

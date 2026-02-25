<?php

declare(strict_types=1);

namespace Semitexa\Orm\Factory;

/**
 * Factory for creating a clean (empty) resource instance.
 * Register in DI per resource type, e.g. UserResourceFactory → ResourceFactory(UserResource::class).
 */
interface ResourceFactoryInterface
{
    /**
     * Create a new resource instance with no data.
     */
    public function create(): object;
}

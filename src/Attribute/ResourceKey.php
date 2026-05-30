<?php

declare(strict_types=1);

namespace Semitexa\Orm\Attribute;

use Attribute;

/**
 * Declares a stable scope key for a Resource, decoupled from its PHP namespace.
 *
 * The key names the invalidation scope for a resource (used to name broadcast
 * channels and key the subscriber reverse-index). When absent, the scope key
 * defaults to the resource's table name (see ResourceMetadata::getResourceKey()).
 */
#[Attribute(Attribute::TARGET_CLASS)]
readonly class ResourceKey
{
    public function __construct(
        public string $key,
    ) {}
}

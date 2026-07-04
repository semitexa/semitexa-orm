<?php

declare(strict_types=1);

namespace Semitexa\Orm\Attribute;

use Attribute;

/**
 * Explicit opt-out from tenant scoping for a resource that is bound to a
 * live invalidation scope (`#[WatchScopes]` / `ExposeAsGraphql(watchScopes:)`).
 *
 * A live-bound resource without `#[TenantScoped]` serves the SAME rows to
 * every tenant's stream — a silent cross-tenant read the moment real tenant
 * data lands in it. The `live_tenancy` ai:verify guard therefore requires
 * every live-bound resource to declare its posture: `#[TenantScoped]` when
 * rows carry a tenant dimension, or this attribute with a human-readable
 * reason when they deliberately do not (demo data, single-operator surfaces).
 *
 * The reason is mandatory: an exemption someone cannot justify in one
 * sentence is a leak waiting for real data.
 */
#[Attribute(Attribute::TARGET_CLASS)]
readonly class TenantExempt
{
    public function __construct(
        public string $reason,
    ) {}
}

<?php

declare(strict_types=1);

namespace Semitexa\Orm\Domain\Event;

use Semitexa\Core\Attribute\AsEvent;
use Semitexa\Orm\Domain\Enum\ResourceChangeOperation;

/**
 * Data-less, scope-keyed signal that a resource changed at the aggregate write
 * chokepoint ({@see \Semitexa\Orm\Application\Service\Persistence\AggregateWriteEngine}).
 *
 * It is an *invalidation signal*, never a data carrier: it announces only that
 * scope {@see $resourceKey} underwent a {@see $operation}. A downstream listener
 * reacts by re-querying / invalidating under its own authorization — the event
 * itself carries no row data, no payload bytes, and no recipient identity.
 *
 * Deliberately `readonly` (immutable invalidation fact) and kiss-relevant only:
 * it has zero awareness of ledger / NATS / node-sync. A future ledger consumer
 * would adapt this through its own adapter, not by widening this event.
 *
 * The tenant is intentionally NOT a field here. The engine has no clean ambient
 * tenant accessor, and the cross-instance publisher (Track P · P3) resolves the
 * tenant synchronously in the same request/coroutine context via
 * `TenantContext::getTenantId() ?? 'default'` (see track-r-design.md §C). Keeping
 * tenant resolution at the publisher avoids coupling the persistence engine to
 * a tenant context store.
 */
#[AsEvent]
final readonly class ResourceChangedEvent
{
    public function __construct(
        public string $resourceKey,
        public ResourceChangeOperation $operation,
    ) {}
}

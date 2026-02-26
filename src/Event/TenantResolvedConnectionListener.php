<?php

declare(strict_types=1);

namespace Semitexa\Orm\Event;

use Semitexa\Core\Attributes\AsEventListener;
use Semitexa\Core\Tenant\Layer\OrganizationLayer;
use Semitexa\Orm\Adapter\ConnectionPoolInterface;
use Semitexa\Tenancy\Event\TenantResolved;

/**
 * Listens to TenantResolved and switches the connection pool to the resolved tenant
 * when the pool supports it (e.g. switchTo method for separate-DB strategy).
 * Integration via event keeps ORM decoupled from tenancy resolution logic.
 */
#[AsEventListener(event: TenantResolved::class)]
final class TenantResolvedConnectionListener
{
    public function __construct(
        private readonly ConnectionPoolInterface $connectionPool,
    ) {}

    public function handle(TenantResolved $event): void
    {
        $org = $event->context->getLayer(new OrganizationLayer());
        if ($org === null || $org->rawValue() === 'default') {
            return;
        }

        if (method_exists($this->connectionPool, 'switchTo')) {
            $this->connectionPool->switchTo($org->rawValue());
        }
    }
}

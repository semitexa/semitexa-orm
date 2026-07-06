<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Handler\DomainListener;

use Semitexa\Core\Attribute\AsEventListener;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Event\EventExecution;
use Semitexa\Core\Log\FallbackErrorLogger;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Core\Tenant\Layer\OrganizationLayer;
use Semitexa\Orm\Adapter\ConnectionPoolInterface;
use Semitexa\Orm\Adapter\TenantSwitchingConnectionPoolInterface;
use Semitexa\Orm\Exception\TenantConnectionSwitchException;
use Semitexa\Tenancy\Domain\Event\TenantResolved;

/**
 * Listens to TenantResolved and opportunistically switches the connection pool
 * to the resolved tenant when the pool supports it, preserving legacy pools
 * that only expose switchTo() without the optional capability contract.
 * Integration via event keeps ORM decoupled from tenancy resolution logic.
 */
#[AsEventListener(event: TenantResolved::class, execution: EventExecution::Sync)]
final class TenantResolvedConnectionListener
{
    #[InjectAsReadonly]
    protected ConnectionPoolInterface $connectionPool;

    #[InjectAsReadonly]
    protected LoggerInterface $logger;

    public function handle(TenantResolved $event): void
    {
        $org = $event->context->getLayer(new OrganizationLayer());
        if ($org === null || $org->rawValue() === 'default') {
            return;
        }

        // Website tenants can resolve by host without requiring separate-db wiring.
        // Capability-aware pools can opt out silently; legacy pools still receive
        // switchTo() so mixed-version integrations keep their previous behavior.
        if (
            $this->connectionPool instanceof TenantSwitchingConnectionPoolInterface
            && !$this->connectionPool->supportsTenantSwitch()
        ) {
            return;
        }

        try {
            $this->connectionPool->switchTo($org->rawValue());
        } catch (\LogicException $e) {
            // A non-default tenant was resolved but the pool could not be
            // switched to its database. Continuing would run this tenant's
            // request against the previous/default connection — a cross-tenant
            // isolation breach. Fail CLOSED: log and abort rather than silently
            // serve or write the wrong tenant's data. Pools that intentionally
            // share one database report supportsTenantSwitch() === false and are
            // skipped above, so this path only fires when a switch was expected.
            $context = [
                'tenant' => $org->rawValue(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ];
            $message = 'Failed to switch connection pool to resolved tenant; aborting to prevent cross-tenant access';

            if (isset($this->logger)) {
                $this->logger->error($message, $context);
            } else {
                FallbackErrorLogger::log($message, $context);
            }

            throw new TenantConnectionSwitchException($org->rawValue(), $e);
        }
    }
}

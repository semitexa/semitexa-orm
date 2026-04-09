<?php

declare(strict_types=1);

namespace Semitexa\Orm\Event;

use Semitexa\Core\Attribute\AsEventListener;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Event\EventExecution;
use Semitexa\Core\Log\FallbackErrorLogger;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Core\Tenant\Layer\OrganizationLayer;
use Semitexa\Orm\Adapter\ConnectionPoolInterface;
use Semitexa\Tenancy\Event\TenantResolved;

/**
 * Listens to TenantResolved and switches the connection pool to the resolved tenant.
 * Integration via event keeps ORM decoupled from tenancy resolution logic.
 */
#[AsEventListener(event: TenantResolved::class, execution: EventExecution::Sync)]
final class TenantResolvedConnectionListener
{
    #[InjectAsReadonly]
    protected ConnectionPoolInterface $connectionPool;

    #[InjectAsReadonly]
    protected ?LoggerInterface $logger = null;

    public function handle(TenantResolved $event): void
    {
        $org = $event->context->getLayer(new OrganizationLayer());
        if ($org === null || $org->rawValue() === 'default') {
            return;
        }

        try {
            $this->connectionPool->switchTo($org->rawValue());
        } catch (\LogicException $e) {
            // Website tenants can resolve by host without requiring separate-db wiring.
            // Explicit separate_db usage still fails later via ConnectionSwitchStrategy.
            $context = [
                'tenant' => $org->rawValue(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ];

            if ($this->logger !== null) {
                $this->logger->warning('Failed to switch connection pool to tenant', $context);
                return;
            }

            FallbackErrorLogger::log('Failed to switch connection pool to tenant', $context);
        }
    }
}

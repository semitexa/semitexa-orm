<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Semitexa\Orm\Application\Service\Persistence\ReplicatedTableRegistration;

/**
 * Arms the ReplicatedWriteGuard for this worker: every #[Replicated] table
 * becomes writable only through the ORM write engine and the replication
 * applier.
 */
#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::WorkerStartAfterContainer->value,
    priority: 0,
    requiresContainer: true,
)]
final class WireServerReplicatedWriteGuardListener implements ServerLifecycleListenerInterface
{
    #[InjectAsReadonly]
    protected ClassDiscovery $discovery;

    public function handle(ServerLifecycleContext $context): void
    {
        if (!isset($this->discovery)) {
            return;
        }

        ReplicatedTableRegistration::fromDiscovery($this->discovery);
    }
}

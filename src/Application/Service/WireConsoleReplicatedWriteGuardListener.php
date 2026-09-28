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
 * Arms the ReplicatedWriteGuard for this console process: every #[Replicated] table
 * becomes writable only through the ORM write engine and the replication
 * applier.
 *
 * Commands write rows too, and none of the worker lifecycle runs for them —
 * the same reason WireConsoleEventDispatcherListener exists.
 */
#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::ConsoleStartAfterContainer->value,
    priority: 0,
    requiresContainer: true,
)]
final class WireConsoleReplicatedWriteGuardListener implements ServerLifecycleListenerInterface
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

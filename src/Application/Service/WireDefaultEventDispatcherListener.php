<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Event\EventDispatcherInterface;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Semitexa\Orm\OrmManager;

/**
 * Track R origin-half (self-init): registers the lazy default-dispatcher resolver
 * from ORM's own lifecycle, so the wiring lives on the allowed orm→core side instead
 * of core force-pushing it (which would be a core→orm reference).
 *
 * Runs at WorkerStartAfterContainer (requiresContainer: true), i.e. AFTER
 * $container->build() — so the discovered EventDispatcher is resolvable and the
 * closure can close over an already-resolved instance. The resolver is still only
 * READ lazily at first-write-engine construction (request time); explicit injection
 * into OrmManager still wins (P2/wiring order is untouched).
 */
#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::WorkerStartAfterContainer->value,
    priority: 0,
    requiresContainer: true,
)]
final class WireDefaultEventDispatcherListener implements ServerLifecycleListenerInterface
{
    #[InjectAsReadonly]
    protected EventDispatcherInterface $dispatcher;

    public function handle(ServerLifecycleContext $context): void
    {
        OrmManager::setDefaultEventDispatcherResolver(
            fn (): EventDispatcherInterface => $this->dispatcher,
        );
    }
}

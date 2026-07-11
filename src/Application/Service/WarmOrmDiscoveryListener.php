<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\BootDiagnostics;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Semitexa\Orm\Discovery\RepositoryDiscovery;
use Semitexa\Orm\OrmManager;

/**
 * Warm the default OrmManager's discovery caches exactly once per worker, in the
 * single-coroutine boot phase, BEFORE the reactor starts serving requests.
 *
 * OrmManager constructs its own ClassDiscovery instance (OrmManager::__construct)
 * — distinct from the container-owned one that WarmResourceMetadataListener
 * already warms. Its classmap scan + AsMapper/AsRepository attribute discovery
 * are blocking-IO coroutine suspension points. Left lazy, the first concurrent
 * request burst after a worker boot would drive many coroutines into that scan
 * at once; ClassDiscovery's per-key coroutine gate now serialises them, but the
 * elected producer still pays a full recursive filesystem scan while the rest
 * park. Doing that work here — during boot, with no concurrency — means the
 * caches are already populated when requests arrive, so the request path only
 * ever reads memoised results.
 *
 * Best-effort: a warmup failure must never crash worker boot. The lazy paths
 * remain correct if warmup is skipped — this only removes the cold-start stall.
 *
 * Runs at WorkerStartFinalize so the container and all registries are built and
 * the default OrmManager is resolvable.
 */
#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::WorkerStartFinalize->value,
    priority: 0,
    requiresContainer: true,
)]
final class WarmOrmDiscoveryListener implements ServerLifecycleListenerInterface
{
    #[InjectAsReadonly]
    protected OrmManager $ormManager;

    public function handle(ServerLifecycleContext $context): void
    {
        try {
            // Populates the manager's ClassDiscovery classmap + the AsMapper
            // attribute cache (build() walks the classmap through discovery).
            $this->ormManager->getMapperRegistry();

            // Populates the AsRepository attribute cache on the same instance.
            RepositoryDiscovery::discoverRepositoryClasses($this->ormManager->getClassDiscovery());
        } catch (\Throwable $e) {
            BootDiagnostics::current()->skip(
                'WarmOrmDiscoveryListener',
                'ORM discovery warmup skipped (falls back to lazy init): ' . $e->getMessage(),
                $e,
            );
        }
    }
}

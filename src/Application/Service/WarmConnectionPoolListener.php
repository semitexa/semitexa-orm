<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Environment;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Semitexa\Orm\Adapter\ConnectionPool;
use Semitexa\Orm\Application\Service\Connection\ConnectionRegistry;

/**
 * Warm the default connection pool at worker start, so the first requests
 * after a deploy/restart do not pay a thundering herd of concurrent TCP+auth
 * handshakes. Runs at WorkerStartFinalize — inside the worker's coroutine
 * context, which the Channel-based pool requires.
 *
 * DB_POOL_WARM controls how many connections to pre-open (clamped to the
 * pool size); it defaults to the full pool, and 0 disables warming — the
 * knob for deployments where workers × pool size would spike against the
 * server's max_connections on a mass restart.
 *
 * Best-effort by contract: a database that is briefly unreachable at worker
 * boot must not crash the worker — the pool lazily connects on first use
 * anyway (fill() releases any slot whose connect failed).
 */
#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::WorkerStartFinalize->value,
    priority: 0,
    requiresContainer: true,
)]
final class WarmConnectionPoolListener implements ServerLifecycleListenerInterface
{
    #[InjectAsReadonly]
    protected ConnectionRegistry $connections;

    public function handle(ServerLifecycleContext $context): void
    {
        $warm = (int) (Environment::getEnvValue('DB_POOL_WARM', '-1') ?? '-1');
        if ($warm === 0) {
            return;
        }

        try {
            $manager = $this->connections->manager();
            if ($manager->getDriver() === 'sqlite') {
                return;
            }

            $pool = $manager->getPool();
            if (! $pool instanceof ConnectionPool) {
                return;
            }

            $pool->fill($warm > 0 ? $warm : null);
        } catch (\Throwable $e) {
            StaticLoggerBridge::warning('orm', 'ORM pool warm-up failed; connections will open lazily.', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}

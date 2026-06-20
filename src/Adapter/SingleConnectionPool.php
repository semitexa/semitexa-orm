<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

/**
 * Single PDO connection — used when Swoole is not available (e.g. CLI).
 * pop() returns the same connection; push() stores it for reuse.
 *
 * This is the fallback pool: OrmManager only selects it when the coroutine
 * runtime looks absent at the first getPool() call (see OrmManager::createPool).
 * That decision is cached for the worker's life, so a wrong early guess — e.g.
 * the first getPool() running before SWOOLE_HOOK_ALL is applied — would freeze a
 * worker onto this pool and then hand the SAME hooked PDO socket to two
 * coroutines under load. Swoole answers that with an uncatchable C-level
 * "Socket already bound to another coroutine" fatal that kills the worker.
 *
 * As defense-in-depth, pop() refuses to double-bind: if the cached connection
 * is still owned by a different, live coroutine, it mints a dedicated
 * short-lived connection instead of returning the bound one. The hot CLI path
 * (no coroutine) is unchanged.
 */
final class SingleConnectionPool implements TenantSwitchingConnectionPoolInterface
{
    private ?\PDO $connection = null;

    /** Coroutine that currently owns the cached connection; -1 = none / non-coroutine. */
    private int $ownerCid = -1;

    public function __construct(
        private readonly \Closure $factory,
    ) {}

    public function pop(float $timeout = -1): \PDO
    {
        $cid = $this->currentCid();

        // Hot path: true single-threaded CLI — one connection, reused. Unchanged.
        if ($cid < 0) {
            return $this->reuseOrCreate();
        }

        // Inside a coroutine. If the cached connection is still bound to a
        // DIFFERENT, live coroutine, returning it would double-bind one hooked
        // PDO socket — the uncatchable fatal described above. Degrade to a
        // dedicated, short-lived connection rather than crash the worker.
        if (
            $this->connection !== null
            && $this->ownerCid >= 0
            && $this->ownerCid !== $cid
            && \Swoole\Coroutine::exists($this->ownerCid)
        ) {
            return ($this->factory)();
        }

        $this->ownerCid = $cid;

        return $this->reuseOrCreate();
    }

    public function push(\PDO $connection): void
    {
        // Re-cache only the owned connection and release ownership so the next
        // coroutine can claim it. Extra crash-avoidance connections are dropped
        // (GC-closed) rather than overwriting the cached one.
        if ($this->connection === null || $this->connection === $connection) {
            $this->connection = $connection;
            $this->ownerCid   = -1;
        }
    }

    public function close(): void
    {
        $this->connection = null;
        $this->ownerCid   = -1;
    }

    public function getSize(): int
    {
        return 1;
    }

    public function getAvailable(): int
    {
        return $this->connection !== null ? 1 : 0;
    }

    public function switchTo(string $tenantId): void
    {
        throw new \LogicException(sprintf(
            'Tenant database switching is not configured for %s (requested tenant: %s).',
            self::class,
            $tenantId,
        ));
    }

    public function supportsTenantSwitch(): bool
    {
        return false;
    }

    private function reuseOrCreate(): \PDO
    {
        if ($this->connection === null) {
            $this->connection = ($this->factory)();
        } else {
            $this->connection = $this->ensureAlive($this->connection);
        }

        return $this->connection;
    }

    private function currentCid(): int
    {
        // Pure-CLI hosts may not have the Swoole extension loaded at all;
        // class_exists(..., false) avoids autoloading and a hard fatal there.
        if (! class_exists(\Swoole\Coroutine::class, false)) {
            return -1;
        }

        return \Swoole\Coroutine::getCid();
    }

    private function ensureAlive(\PDO $connection): \PDO
    {
        try {
            $stmt = $connection->query('SELECT 1');
            if ($stmt === false) {
                return ($this->factory)();
            }

            return $connection;
        } catch (\PDOException) {
            return ($this->factory)();
        }
    }
}

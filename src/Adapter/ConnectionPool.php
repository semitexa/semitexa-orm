<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

use Swoole\Atomic;
use Swoole\Coroutine\Channel;

class ConnectionPool implements TenantSwitchingConnectionPoolInterface
{
    /**
     * PHP shutdown phase flag.
     *
     * Swoole tears down its internal Channel state during the PHP shutdown
     * Any Channel method called after that point — even isEmpty() —
     * raises a true PHP fatal ("must call constructor first") that BYPASSES
     * try/catch. The fatal originates inside the C extension before any
     * userland exception handling can run.
     *
     * In long-running workers or tests, object destruction may occur during
     * PHP shutdown after the runtime is gone. Without a guard, every
     * Swoole-using test session ends with a fatal that aborts PHPUnit
     * before the final summary line.
     *
     * The shutdown_function below flips this flag BEFORE Channel destruction.
     * close() checks the flag first and skips Channel ops when the runtime
     * is already gone — no userland operation matters at that point because
     * the process is exiting anyway.
     */
     private static bool $phpShuttingDown = false;
    private static bool $shutdownHookRegistered = false;

    private ?Channel $pool;

    /**
     * Atomic counter for the number of connections created so far.
     *
     * Swoole\Atomic operations (add, cmpset) are implemented via C-level
     * atomic instructions — they never suspend the coroutine, so there is
     * no window for another coroutine to observe a half-updated value.
     * This eliminates the race condition where two coroutines simultaneously
     * passed the `isEmpty() && created < size` check and both created a
     * new connection, exceeding the pool limit.
     */
    private Atomic $created;

    public function __construct(
        private readonly int $size,
        private readonly \Closure $factory,
    ) {
        self::registerShutdownHookOnce();
        $this->pool    = new Channel($size);
        $this->created = new Atomic(0);
    }

    private static function registerShutdownHookOnce(): void
    {
        if (self::$shutdownHookRegistered) {
            return;
        }
        self::$shutdownHookRegistered = true;
        register_shutdown_function(static function (): void {
            self::$phpShuttingDown = true;
        });
    }

    public function pop(float $timeout = -1): \PDO
    {
        if ($this->pool === null) {
            throw new \RuntimeException('Connection pool is closed.');
        }

        // Outside a coroutine the Swoole Channel cannot be operated ("API must be
        // called in the coroutine") — a fatal that bypasses try/catch. Hand out a
        // direct, un-pooled connection instead (push() drops it). The Channel pool
        // is only meaningful inside coroutines (the Swoole server request path);
        // CLI / phpunit / any non-coroutine caller reached while hooks are globally
        // enabled gets a fresh connection per call. Never claims a pool slot.
        if (! self::inCoroutine()) {
            return ($this->factory)();
        }

        // Atomically claim a slot: increment only if we are still below the
        // limit. cmpset(expected, new) returns true exactly once per slot.
        $current = $this->created->get();

        if ($this->pool->isEmpty() && $current < $this->size) {
            if ($this->created->cmpset($current, $current + 1)) {
                // We won the race — create and return the new connection
                // without pushing it to the channel first. The cmpset above
                // has claimed the slot; createForClaimedSlot() releases it if
                // the connect fails.
                return $this->createForClaimedSlot();
            }
        }

        // Either pool is not empty, limit is reached, or another coroutine
        // won the cmpset race — wait for a returned connection.
        $connection = $this->pool->pop($timeout);

        if ($connection === false) {
            throw new \RuntimeException('Failed to obtain database connection from pool (timeout).');
        }

        return $this->ensureAlive($connection);
    }

    public function push(\PDO $connection): void
    {
        $pool = $this->pool;
        if (! $pool instanceof Channel) {
            return;
        }

        // Outside a coroutine `Channel->push()` fatals ("API must be called in the
        // coroutine"). The connection handed out by pop() in this state was
        // un-pooled, so drop it (it closes on destruction) instead of crashing.
        if (! self::inCoroutine()) {
            return;
        }

        $pool->push($connection);
    }

    /**
     * Whether the current execution is inside a Swoole coroutine. The Channel-based
     * pool can only be operated from a coroutine; non-coroutine callers (CLI,
     * phpunit, or any code reached while Swoole hooks are globally enabled but no
     * coroutine is active) must bypass the Channel. Guarded by class_exists so
     * non-Swoole hosts never reach getCid().
     */
    private static function inCoroutine(): bool
    {
        return class_exists(\Swoole\Coroutine::class, false)
            && \Swoole\Coroutine::getCid() >= 0;
    }

    /**
     * Eagerly open all connections and push them into the channel.
     * Call this once during worker start (inside a coroutine context) to
     * avoid any lazy-creation overhead on the first requests.
     */
    public function fill(): void
    {
        $pool = $this->pool;
        if (! $pool instanceof Channel) {
            throw new \RuntimeException('Connection pool is closed.');
        }

        $current = $this->created->get();

        while ($current < $this->size) {
            if ($this->created->cmpset($current, $current + 1)) {
                // createForClaimedSlot() releases the slot if the factory
                // throws (DB not yet reachable at worker boot), so a later
                // retry/pop() can still fill the pool instead of it being
                // permanently short one connection.
                $pool->push($this->createForClaimedSlot());
            }

            $current = $this->created->get();
        }
    }

    public function close(): void
    {
        if ($this->pool === null) {
            return;
        }

        // PHP shutdown phase — Channel methods would raise an uncatchable
        // Swoole\Error fatal. Skip the drain; the OS reclaims the Channel.
        if (self::$phpShuttingDown) {
            $this->pool = null;
            return;
        }

        // Outside a coroutine, Channel methods are equally illegal: a Channel
        // constructed without a coroutine context defers its C-level init, and
        // even isEmpty() then raises the same "must call constructor first"
        // fatal (observed live: an OrmManager GC'd during container build in a
        // coroutineless test process). Dropping the reference releases the
        // queued connections via refcounting — same outcome as the drain.
        if (!self::inCoroutine()) {
            $this->pool = null;
            $this->created->set(0);
            return;
        }

        try {
            while (!$this->pool->isEmpty()) {
                $this->pool->pop();
            }

            $this->pool->close();
        } catch (\Throwable) {
            // Catch-all for normal-runtime cleanup edge cases — the pool is
            // going away regardless, so any cleanup error is moot.
        } finally {
            $this->pool = null;
            $this->created->set(0);
        }
    }

    public function getSize(): int
    {
        return $this->size;
    }

    /**
     * Whether pop() is currently handing out throwaway connections: outside a
     * coroutine every pop() mints a fresh PDO and push() drops it, so callers
     * must not hold references (e.g. cached statements) that outlive the call —
     * each held reference keeps a real server socket open.
     */
    public function handsOutEphemeralConnections(): bool
    {
        return ! self::inCoroutine();
    }

    public function getAvailable(): int
    {
        if ($this->pool === null) {
            return 0;
        }

        try {
            return $this->pool->length();
        } catch (\Error) {
            return 0;
        }
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

    /**
     * Verify that a pooled connection is still alive and reconnect if not.
     *
     * Connections that sat idle in the channel may have been dropped by MySQL
     * (wait_timeout) or by an intermediate proxy/firewall. A cheap SELECT 1
     * detects the broken socket before the caller issues a real query.
     *
     * Only called for connections retrieved from the channel (not for freshly
     * created ones), so the overhead is limited to idle connections.
     *
     * The slot was already claimed when the connection was first opened, so a
     * successful reconnect leaves `created` unchanged. But the reconnect is a
     * real PDO connect that can fail (MySQL briefly unreachable exactly when we
     * notice the socket is dead): the stale connection is then discarded and no
     * replacement is produced, so the slot MUST be released — otherwise it
     * leaks identically to the pop()/fill() paths and ratchets the pool into
     * the full-but-empty deadlock. createForClaimedSlot() does that release.
     */
    private function ensureAlive(\PDO $connection): \PDO
    {
        try {
            $stmt = $connection->query('SELECT 1');
            if ($stmt === false) {
                return $this->createForClaimedSlot();
            }

            return $connection;
        } catch (\PDOException) {
            // Connection is stale — replace it with a fresh one.
            return $this->createForClaimedSlot();
        }
    }

    /**
     * Open a connection for a slot already counted in `created`, releasing the
     * slot if the factory throws.
     *
     * Every caller has accounted for the slot before calling: pop()/fill() via
     * the cmpset(+1) that just claimed it, ensureAlive() via the slot the dead
     * pooled connection still occupies. A PDO connect can fail — a transient
     * network blip, MySQL momentarily refusing, an auth hiccup. If it does the
     * slot must be released here, because nothing else ever decrements `created`
     * (only close() resets it wholesale). Leaking `size` slots wedges the pool
     * full-but-empty forever, so every pop() blocks on Channel->pop(-1): the
     * ratcheting deadlock that took down production with all workers asleep in
     * pop(). Releasing the slot lets a later pop() open a fresh connection
     * instead, so transient DB unavailability degrades gracefully.
     */
    private function createForClaimedSlot(): \PDO
    {
        try {
            return ($this->factory)();
        } catch (\Throwable $e) {
            $this->created->sub(1);
            throw $e;
        }
    }
}

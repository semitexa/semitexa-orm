<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Orm\Exception\PoolExhaustedException;
use Swoole\Atomic;
use Swoole\Coroutine\Channel;

class ConnectionPool implements TenantSwitchingConnectionPoolInterface, EphemeralConnectionAwareInterface
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

    /**
     * Maximum time a waiting pop() parks on the channel before re-checking
     * whether a slot has freed up so it can create a replacement itself.
     *
     * A push() wakes Channel->pop() instantly regardless of this value, so
     * under healthy load it costs nothing. It only bounds how long a waiter
     * stays parked when a concurrent createForClaimedSlot() failure released a
     * slot (which decrements `created` but cannot wake coroutines already
     * asleep in Channel->pop()): the loop in pop() re-evaluates the create path
     * every slice, so such a waiter self-heals within WAIT_SLICE_SECONDS
     * instead of blocking until some unrelated later push().
     */
    private const WAIT_SLICE_SECONDS = 0.5;

    /**
     * How long pop() waits for a connection when the caller names no timeout.
     *
     * The callers that matter — MysqlAdapter::executeRecorded/queryRecorded and
     * TransactionManager::runOuter — all call pop() with no argument, so the
     * previous default of -1 meant "park forever". That is what turned pool
     * exhaustion into a silent hang: the request was read, no response was ever
     * written, and every worker thread sat idle, so nothing in the logs, the
     * process table or the socket queues said a database connection was the
     * thing being waited on.
     *
     * A request that has waited this long for a connection is not going to be
     * saved by waiting longer; the existing RuntimeException names the pool and
     * fails the request instead of the whole worker going quiet. Callers that
     * genuinely want to block indefinitely can still pass -1.
     */
    private const DEFAULT_POP_TIMEOUT_SECONDS = 10.0;

    /**
     * Only connections idle at least this long get the `SELECT 1` liveness
     * ping on checkout. Under healthy load connections cycle in milliseconds,
     * and pinging EVERY pop doubles the round-trips per query — the pool then
     * sustains roughly half the throughput it is sized for. One second of
     * idleness is far below any server-side disconnect horizon (wait_timeout
     * defaults to hours) while skipping the ping on virtually every hot-path
     * checkout. A connection with NO idle stamp (adopted, pre-warm) is always
     * pinged — conservative by default.
     */
    private const IDLE_PING_SECONDS = 1.0;

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

    /**
     * The connections that hold a slot counted in `created`.
     *
     * Exactly the ones createForClaimedSlot() produced — pop()/fill() claim a
     * slot with cmpset before calling it, and ensureAlive() passes on the slot
     * its dead connection held. The direct `($this->factory)()` that pop()
     * hands out to a non-coroutine caller is deliberately absent: it never
     * claimed a slot, so push() must not release one for it.
     *
     * A WeakMap, not spl_object_id: an id is reused once its object is
     * collected, and a stale entry would then make some later, unrelated PDO
     * look like a slot holder and release a slot that was never claimed —
     * over-creating past `size` instead of leaking, which is the same bug
     * pointing the other way. Entries here disappear with their connection.
     *
     * @var \WeakMap<\PDO, true>
     */
    private \WeakMap $slotHolders;

    /**
     * The process this pool's state belongs to.
     *
     * A pool built during boot is inherited by every worker the server forks,
     * and none of the state below survives that honestly — see
     * {@see adoptForCurrentProcess()}.
     */
    private int $ownerPid;

    /**
     * Outstanding borrows, per coroutine: cid => (spl_object_id => PDO).
     *
     * Deliberately STRONG references. A coroutine that dies without pushing —
     * an uncaught throw across a yield, a killed coroutine, code that simply
     * forgot — used to leak its slot permanently: the PDO's WeakMap entries
     * vanish on GC without anyone calling `created->sub(1)`, so the pool
     * ratchets toward full-but-empty and every later pop() times out. The
     * strong reference keeps the connection recoverable until the coroutine's
     * defer (armed on its first borrow) returns whatever is still checked out.
     *
     * @var array<int, array<int, \PDO>>
     */
    private array $borrowedByCid = [];

    /** Which coroutine borrowed a connection — reverse index for push(). @var \WeakMap<\PDO, int> */
    private \WeakMap $borrowerOf;

    /** When a connection was last returned to the channel. @var \WeakMap<\PDO, float> */
    private \WeakMap $idleSince;

    /** Coroutines that already armed their give-back defer. @var array<int, true> */
    private array $reclaimArmed = [];

    /**
     * Reliability counters, exposed via getStats() (orm:status). Deliberately
     * plain ints, not Atomics: they are per-worker observability, and every
     * mutation happens without a coroutine yield in between.
     *
     * @var array{reconnects: int, discards: int, exhausted_timeouts: int, reclaimed_from_dead_coroutines: int, warmed: int}
     */
    private array $stats = [
        'reconnects' => 0,
        'discards' => 0,
        'exhausted_timeouts' => 0,
        'reclaimed_from_dead_coroutines' => 0,
        'warmed' => 0,
    ];

    public function __construct(
        private readonly int $size,
        private readonly \Closure $factory,
    ) {
        self::registerShutdownHookOnce();
        $this->pool        = new Channel($size);
        $this->created     = new Atomic(0);
        $this->slotHolders = new \WeakMap();
        $this->borrowerOf  = new \WeakMap();
        $this->idleSince   = new \WeakMap();
        $this->ownerPid    = getmypid();
    }

    /**
     * Re-own the pool after a fork.
     *
     * A pool created before the server forks its workers is inherited by all of
     * them, and two pieces of its state then mean the wrong thing:
     *
     *   - `created` is a Swoole\Atomic, which lives in SHARED memory. A counter
     *     meant to cap ONE worker's connections instead capped every worker put
     *     together, so DB_POOL_SIZE silently became a budget for the whole
     *     server. Once the workers had collectively created `size` connections,
     *     no worker was allowed to open another — while each worker's own
     *     channel held only the handful it had made. Workers that lost the race
     *     parked on an empty channel that nothing would ever refill: requests
     *     read and never answered, with every worker thread idle. Observed on a
     *     consumer as 10 connections shared by 8 workers.
     *
     *   - the Channel and any connections in it are inherited copies. A PDO
     *     socket used from two processes at once corrupts both sides of the
     *     conversation, so inherited connections must be dropped, never served.
     *
     * Dropping the channel is safe: nothing has been handed out in this process
     * yet, and the parent's connections close with their last reference.
     */
    private function adoptForCurrentProcess(): void
    {
        $pid = getmypid();
        if ($pid === $this->ownerPid) {
            return;
        }

        $this->ownerPid = $pid;

        // A pool closed before the fork stays closed — do not resurrect it.
        if (! $this->pool instanceof Channel) {
            return;
        }

        $this->pool        = new Channel($this->size);
        $this->created     = new Atomic(0);
        $this->slotHolders = new \WeakMap();
        $this->borrowedByCid = [];
        $this->borrowerOf    = new \WeakMap();
        $this->idleSince     = new \WeakMap();
        $this->reclaimArmed  = [];
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

    public function pop(?float $timeout = null): \PDO
    {
        $this->adoptForCurrentProcess();

        if ($this->pool === null) {
            throw new \RuntimeException('Connection pool is closed.');
        }

        // null means "no preference" and takes the bounded default; -1 stays
        // available for a caller that really does mean wait forever.
        $timeout ??= self::DEFAULT_POP_TIMEOUT_SECONDS;

        // Outside a coroutine the Swoole Channel cannot be operated ("API must be
        // called in the coroutine") — a fatal that bypasses try/catch. Hand out a
        // direct, un-pooled connection instead (push() drops it). The Channel pool
        // is only meaningful inside coroutines (the Swoole server request path);
        // CLI / phpunit / any non-coroutine caller reached while hooks are globally
        // enabled gets a fresh connection per call. Never claims a pool slot.
        if (! self::inCoroutine()) {
            return ($this->factory)();
        }

        // Claim-or-wait loop. Each iteration first tries to claim a free slot
        // and create a fresh connection; only if the pool is full does it wait
        // for a returned one. The loop re-runs the claim check on every wake,
        // so a slot freed by a concurrent createForClaimedSlot() failure — which
        // decrements `created` but cannot wake coroutines already parked in
        // Channel->pop() — is picked up here instead of leaving this waiter
        // blocked until some unrelated later push(). That closes the residual
        // deadlock window that a plain slot-release alone does not.
        $remaining = $timeout;

        while (true) {
            // Re-read every iteration: a concurrent close() nulls $this->pool and
            // wakes parked waiters, and we must not hand out a connection from a
            // closed pool.
            $pool = $this->requirePool();

            // Atomically claim a slot: increment only if we are still below the
            // limit. cmpset(expected, new) returns true exactly once per slot.
            $current = $this->created->get();

            if ($pool->isEmpty() && $current < $this->size
                && $this->created->cmpset($current, $current + 1)) {
                // We won the race — create and return the new connection
                // without pushing it to the channel first. The cmpset above
                // has claimed the slot; createForClaimedSlot() releases it if
                // the connect fails.
                return $this->lendToCoroutine($this->createForClaimedSlot());
            }

            // Pool is full (or another coroutine won the cmpset race) — wait for
            // a returned connection. A push() wakes this instantly; the bounded
            // slice only caps how long we park before re-checking the claim path
            // above, so a freed slot never strands this waiter indefinitely.
            $wait = $remaining < 0
                ? self::WAIT_SLICE_SECONDS
                : min($remaining, self::WAIT_SLICE_SECONDS);

            $connection = $pool->pop($wait);
            if ($connection !== false) {
                return $this->lendToCoroutine($this->ensureAliveIfIdle($connection));
            }

            if ($remaining >= 0) {
                $remaining -= $wait;
                if ($remaining <= 0) {
                    $this->stats['exhausted_timeouts']++;
                    StaticLoggerBridge::warning('orm', 'Connection pool exhausted: pop() timed out.', [
                        'size' => $this->size,
                        'created' => $this->created->get(),
                        'available' => $this->getAvailable(),
                        'timeout_seconds' => $timeout,
                    ]);
                    throw new PoolExhaustedException('Failed to obtain database connection from pool (timeout).');
                }
            }
        }
    }

    public function push(\PDO $connection): void
    {
        $this->adoptForCurrentProcess();
        $this->unrecordBorrow($connection);

        $pool = $this->pool;
        if (! $pool instanceof Channel) {
            // Pool already closed. close() resets `created` wholesale, so there
            // is no slot left to give back — just forget the connection.
            unset($this->slotHolders[$connection]);

            return;
        }

        // A connection must NEVER re-enter the pool mid-transaction: the next
        // coroutine would inherit an open transaction and its writes would be
        // committed or rolled back by whoever touches the connection later —
        // silent cross-request data corruption. inTransaction() is a local
        // client-side flag (no IO), so this costs nothing on the clean path.
        // Limitation: it only tracks PDO::beginTransaction(); a raw
        // `START TRANSACTION` sent as a query is invisible to it, which is why
        // no framework code path issues one against a pooled adapter.
        if (self::hasOpenTransaction($connection)) {
            try {
                $connection->rollBack();
            } catch (\Throwable) {
                // The connection died mid-transaction and cannot be cleaned —
                // discard it and give back its slot so the pool mints a fresh
                // replacement instead of recycling poisoned state. Counted like
                // any other discard: orm:status reporting discards=0 during a
                // poisoned-connection incident sends the operator hunting the
                // wrong thing.
                $this->stats['discards']++;
                $this->releaseSlotOf($connection);

                return;
            }
        }

        // Outside a coroutine `Channel->push()` fatals ("API must be called in the
        // coroutine"). The connection cannot go back into the channel, so it is
        // dropped (it closes on destruction) instead of crashing.
        //
        // Dropping it is only half the job. pop() hands out TWO kinds of
        // connection: an un-pooled one minted directly for a non-coroutine
        // caller, which never claimed a slot, and a real pooled one that did.
        // A pooled connection reaches this branch whenever pop() ran inside a
        // coroutine but push() does not — a `finally` running during coroutine
        // teardown, a destructor, a deferred continuation. Dropping it without
        // releasing its slot costs the pool one slot forever, and `size` of
        // those wedge it full-but-empty: every later pop() then parks on a
        // channel nothing will ever push to, with the worker looking idle. That
        // is the ratcheting deadlock described above createForClaimedSlot(),
        // reached from the other side.
        if (! self::inCoroutine()) {
            $this->releaseSlotOf($connection);

            return;
        }

        $this->idleSince[$connection] = microtime(true);
        $pool->push($connection);
    }

    /**
     * Record a borrow for the current coroutine and arm (once per coroutine)
     * a defer that gives back whatever is still checked out when the
     * coroutine ends. The defer runs inside the ending coroutine, so Channel
     * ops are legal there; push() applies its usual transaction hygiene, so a
     * connection abandoned mid-transaction is rolled back or discarded, never
     * recycled dirty. On the normal path the borrow is unrecorded by push()
     * and the defer finds nothing — the cost is one array write per checkout.
     */
    private function lendToCoroutine(\PDO $connection): \PDO
    {
        $cid = \Swoole\Coroutine::getCid();
        if ($cid < 0) {
            return $connection;
        }

        $this->borrowedByCid[$cid][spl_object_id($connection)] = $connection;
        $this->borrowerOf[$connection] = $cid;

        if (! isset($this->reclaimArmed[$cid])) {
            $this->reclaimArmed[$cid] = true;
            \Swoole\Coroutine::defer(function () use ($cid): void {
                unset($this->reclaimArmed[$cid]);
                $leaked = $this->borrowedByCid[$cid] ?? [];
                unset($this->borrowedByCid[$cid]);

                if (self::$phpShuttingDown) {
                    return;
                }

                foreach ($leaked as $connection) {
                    $this->stats['reclaimed_from_dead_coroutines']++;
                    $this->push($connection);
                }
                if ($leaked !== []) {
                    StaticLoggerBridge::warning('orm', 'Reclaimed connection(s) from a coroutine that ended without returning them.', [
                        'count' => count($leaked),
                        'cid' => $cid,
                    ]);
                }
            });
        }

        return $connection;
    }

    /**
     * Drop a connection from the pool's accounting WITHOUT re-queueing it.
     *
     * For a connection known to be dead (the server closed it mid-query):
     * pushing it back would recycle a poisoned socket to the next coroutine,
     * while silently dropping it would leak its slot. This frees the slot so
     * pop() can mint a fresh replacement, and forgets the borrow so the
     * coroutine-end reclaim does not resurrect it.
     */
    public function discard(\PDO $connection): void
    {
        $this->stats['discards']++;
        $this->unrecordBorrow($connection);
        $this->releaseSlotOf($connection);
    }

    private function unrecordBorrow(\PDO $connection): void
    {
        $cid = $this->borrowerOf[$connection] ?? null;
        if ($cid === null) {
            return;
        }

        unset(
            $this->borrowerOf[$connection],
            $this->borrowedByCid[$cid][spl_object_id($connection)],
        );
        if (($this->borrowedByCid[$cid] ?? []) === []) {
            unset($this->borrowedByCid[$cid]);
        }
    }

    /**
     * inTransaction() on a healthy connection is a local flag read (no IO),
     * but an uninitialized or torn-down PDO raises \Error — treat any failure
     * to answer as "no transaction" and let the normal path proceed.
     */
    private static function hasOpenTransaction(\PDO $connection): bool
    {
        try {
            return $connection->inTransaction();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Give back the slot `$connection` holds, if it holds one.
     *
     * Only connections createForClaimedSlot() produced are counted in
     * `created`; an un-pooled one minted for a non-coroutine caller is not, and
     * releasing a slot for it would let the pool create past `size`.
     */
    private function releaseSlotOf(\PDO $connection): void
    {
        if (! isset($this->slotHolders[$connection])) {
            return;
        }

        unset($this->slotHolders[$connection]);
        $this->created->sub(1);
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
     * Return the live channel or fail if the pool has been closed.
     *
     * Read fresh from the property so that a close() on another coroutine
     * (which sets $this->pool = null) is observed by a waiting pop() loop
     * rather than the loop continuing to operate a torn-down pool.
     */
    private function requirePool(): Channel
    {
        $pool = $this->pool;
        if ($pool === null) {
            throw new \RuntimeException('Connection pool is closed.');
        }

        return $pool;
    }

    /**
     * Eagerly open all connections and push them into the channel.
     * Call this once during worker start (inside a coroutine context) to
     * avoid any lazy-creation overhead on the first requests.
     */
    public function fill(?int $target = null): void
    {
        $this->adoptForCurrentProcess();

        $pool = $this->pool;
        if (! $pool instanceof Channel) {
            throw new \RuntimeException('Connection pool is closed.');
        }

        $target = $target === null ? $this->size : min($target, $this->size);
        $current = $this->created->get();

        while ($current < $target) {
            if ($this->created->cmpset($current, $current + 1)) {
                // createForClaimedSlot() releases the slot if the factory
                // throws (DB not yet reachable at worker boot), so a later
                // retry/pop() can still fill the pool instead of it being
                // permanently short one connection.
                $connection = $this->createForClaimedSlot();
                $this->stats['warmed']++;
                $this->idleSince[$connection] = microtime(true);
                $pool->push($connection);
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
            $this->borrowedByCid = [];
            $this->reclaimArmed  = [];
        }
    }

    public function getSize(): int
    {
        return $this->size;
    }

    /**
     * Per-worker reliability counters: reconnects, discards, exhaustion
     * timeouts, coroutine-death reclaims, warm-up count. Consumed by
     * orm:status; cheap enough to call anytime.
     *
     * @return array{size: int, created: int, available: int, reconnects: int, discards: int, exhausted_timeouts: int, reclaimed_from_dead_coroutines: int, warmed: int}
     */
    public function getStats(): array
    {
        return array_merge([
            'size' => $this->size,
            'created' => $this->created->get(),
            'available' => $this->getAvailable(),
        ], $this->stats);
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

    /**
     * Precise per-connection form of the above: a connection holding a slot
     * (createForClaimedSlot() produced it) is pooled and will come back; a
     * direct non-coroutine mint never claimed one and is dropped on push().
     */
    public function isEphemeralConnection(\PDO $connection): bool
    {
        return ! isset($this->slotHolders[$connection]);
    }

    public function getAvailable(): int
    {
        $this->adoptForCurrentProcess();

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
    /**
     * Health-check the connection only when it sat idle long enough to have
     * plausibly died; a connection returned milliseconds ago is served as-is.
     * See IDLE_PING_SECONDS for the trade-off. A dead connection that slips
     * through unpinged still fails safe: the adapters classify the failure,
     * discard the connection (freeing its slot) and transparently replay
     * read-only statements on a fresh one.
     */
    private function ensureAliveIfIdle(\PDO $connection): \PDO
    {
        $idleSince = $this->idleSince[$connection] ?? null;
        if ($idleSince !== null && (microtime(true) - $idleSince) < self::IDLE_PING_SECONDS) {
            return $connection;
        }

        return $this->ensureAlive($connection);
    }

    private function ensureAlive(\PDO $connection): \PDO
    {
        try {
            $stmt = $connection->query('SELECT 1');
            if ($stmt === false) {
                return $this->replaceDeadConnection($connection);
            }

            return $connection;
        } catch (\PDOException) {
            // Connection is stale — replace it with a fresh one.
            return $this->replaceDeadConnection($connection);
        }
    }

    /**
     * Hand the dead connection's slot to its replacement.
     *
     * The slot stays claimed across the swap, so `created` is untouched here;
     * createForClaimedSlot() releases it only if the reconnect itself fails.
     * The dead connection is deregistered first so it can never release the
     * slot a second time — a WeakMap entry outlives the object only until the
     * next collection, and a stray push() of a discarded connection in that
     * window would otherwise hand back a slot its replacement is still using.
     */
    private function replaceDeadConnection(\PDO $dead): \PDO
    {
        $this->stats['reconnects']++;
        StaticLoggerBridge::debug('orm', 'Replaced a dead pooled connection.', [
            'created' => $this->created->get(),
            'size' => $this->size,
        ]);
        unset($this->slotHolders[$dead]);

        return $this->createForClaimedSlot();
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
            $connection = ($this->factory)();
        } catch (\Throwable $e) {
            $this->created->sub(1);
            throw $e;
        }

        // Remember that this one carries the slot, so push() can tell it apart
        // from an un-pooled connection when it has to drop rather than return it.
        $this->slotHolders[$connection] = true;

        return $connection;
    }
}

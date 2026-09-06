<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service\Transaction;

use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Core\Event\EventDispatcherInterface;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Orm\Adapter\ConnectionPoolInterface;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\DriverErrorClassifier;
use Semitexa\Orm\Adapter\SqliteAdapter;
use Semitexa\Orm\Exception\ConnectionLostException;
use Semitexa\Orm\Exception\DeadlockException;
use Semitexa\Orm\Exception\LockWaitTimeoutException;
use Semitexa\Orm\Exception\TransactionLockTimeoutException;

class TransactionManager
{
    /**
     * The transaction state (active connection, nesting depth, buffered events)
     * is REQUEST-SCOPED, but this manager is a worker-singleton: one instance
     * per worker serves every request coroutine on that worker. Under Swoole,
     * beginTransaction()/exec() yield on the DB socket, so a second coroutine
     * can run between them. Were the state a plain instance field, coroutine B
     * would observe coroutine A's depth/PDO mid-flight — reusing A's connection,
     * corrupting A's transaction, and cross-leaking A's pendingEvents. So the
     * state is keyed per coroutine via CoroutineLocal: each coroutine (each
     * request) gets its own transaction, auto-cleaned when the coroutine ends;
     * in CLI (no coroutine) it falls back to a process-static that each
     * transaction resets in its finally. Within ONE coroutine, run() → nested
     * run() still share state (correct nesting). $eventDispatcher stays an
     * instance field — it is boot config, identical for every request.
     *
     * The keys are namespaced by CONNECTION NAME: each named connection has its
     * own TransactionManager (one per OrmManager, see ConnectionRegistry), and
     * were the keys shared, a transaction opened on connection B while A's is
     * active in the same coroutine would read A's depth, take the nested-
     * savepoint branch, and run B's writes on A's PDO — the wrong database.
     */
    private const KEY_ACTIVE_CONNECTION = 'orm.tx.%s.activeConnection';
    private const KEY_ACTIVE_ADAPTER = 'orm.tx.%s.activeAdapter';
    private const KEY_DEPTH = 'orm.tx.%s.depth';
    private const KEY_PENDING_EVENTS = 'orm.tx.%s.pendingEvents';

    /**
     * How long a coroutine waits for the SQLite transaction lock before giving
     * up. Matches ConnectionPool::DEFAULT_POP_TIMEOUT_SECONDS: a request that
     * has waited this long to start a transaction is not saved by waiting more.
     */
    private const SQLITE_TX_LOCK_TIMEOUT_SECONDS = 10.0;

    /**
     * One-token lock serialising outer SQLite transactions across the
     * coroutines of this worker. A plain instance field on purpose — unlike the
     * transaction state above it is SHARED state, and that is the entire point.
     * Null until the first transaction runs inside a coroutine.
     */
    private ?\Swoole\Coroutine\Channel $sqliteTxMutex = null;

    public function __construct(
        private readonly ConnectionPoolInterface $pool,
        private readonly DatabaseAdapterInterface $adapter,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private readonly string $connectionName = 'default',
    ) {}

    private function key(string $template): string
    {
        return sprintf($template, $this->connectionName);
    }

    /** Active PDO connection for the current coroutine's (outermost) transaction, null when idle. */
    private function activeConnection(): ?\PDO
    {
        return CoroutineLocal::get($this->key(self::KEY_ACTIVE_CONNECTION));
    }

    private function setActiveConnection(?\PDO $pdo): void
    {
        CoroutineLocal::set($this->key(self::KEY_ACTIVE_CONNECTION), $pdo);
    }

    /** Nesting depth for the current coroutine: 0 = no transaction, 1 = outer BEGIN, 2+ = savepoints. */
    private function depth(): int
    {
        return (int) CoroutineLocal::get($this->key(self::KEY_DEPTH), 0);
    }

    private function setDepth(int $depth): void
    {
        CoroutineLocal::set($this->key(self::KEY_DEPTH), $depth);
    }

    /** @return object[] Buffered events for the current coroutine, dispatched after successful outer commit. */
    private function pendingEvents(): array
    {
        return CoroutineLocal::get($this->key(self::KEY_PENDING_EVENTS), []);
    }

    /** @param object[] $events */
    private function setPendingEvents(array $events): void
    {
        CoroutineLocal::set($this->key(self::KEY_PENDING_EVENTS), $events);
    }

    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher): void
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    public function bufferEvent(object $event): void
    {
        $events = $this->pendingEvents();
        $events[] = $event;
        $this->setPendingEvents($events);
    }

    public function isActive(): bool
    {
        return $this->depth() > 0;
    }

    /**
     * The adapter bound to the current coroutine's open transaction on THIS
     * connection, or null when no transaction is active. Read paths route
     * through it (see TransactionAwareAdapter) so queries inside a transaction
     * see their own uncommitted writes instead of reading pre-commit state on
     * an unrelated pooled connection — and so a coroutine holding the
     * transaction connection never pops a second one (the pool-exhaustion
     * shape: `size` such coroutines each hold one connection while waiting for
     * another).
     */
    public function currentAdapter(): ?DatabaseAdapterInterface
    {
        $adapter = CoroutineLocal::get($this->key(self::KEY_ACTIVE_ADAPTER));

        return $adapter instanceof DatabaseAdapterInterface ? $adapter : null;
    }

    private function setCurrentAdapter(?DatabaseAdapterInterface $adapter): void
    {
        CoroutineLocal::set($this->key(self::KEY_ACTIVE_ADAPTER), $adapter);
    }

    /** @return object[] */
    public function getPendingEvents(): array
    {
        return $this->pendingEvents();
    }

    public function clearPendingEvents(): void
    {
        $this->setPendingEvents([]);
    }

    /**
     * Execute a callable within a database transaction.
     *
     * Outer call: pops a connection from the pool, issues BEGIN.
     * Nested call: reuses the same connection and creates a SAVEPOINT instead.
     *
     * On success (outer): COMMIT, return connection to pool.
     * On success (nested): RELEASE SAVEPOINT.
     * On exception (outer): ROLLBACK, return connection to pool, re-throw.
     * On exception (nested): ROLLBACK TO SAVEPOINT, re-throw.
     *
     * @template T
     * @param callable(DatabaseAdapterInterface): T $callback
     * @return T
     */
    public function run(callable $callback): mixed
    {
        if ($this->depth() === 0) {
            return $this->runOuter($callback);
        }

        return $this->runNested($callback);
    }

    /**
     * run() with automatic replay on transient transactional failures:
     * deadlock (the server already rolled the victim back), lock-wait timeout,
     * and a connection lost mid-transaction. The retry unit is the WHOLE
     * transaction — the callback re-executes from the top on a clean
     * connection, which is the only correct granularity (replaying a single
     * statement of a rolled-back transaction silently applies a partial
     * write). Consequence for callers: the callback must be safe to run more
     * than once — pure DB work is, side effects outside the transaction
     * (HTTP calls, file writes) are not, and belong outside runWithRetry().
     *
     * A NESTED call is never retried here: the server rolled back the OUTER
     * transaction, so only the outermost caller can meaningfully replay.
     *
     * @template T
     * @param callable(DatabaseAdapterInterface): T $callback
     * @param int $attempts Total tries, including the first (minimum 1)
     * @return T
     */
    public function runWithRetry(callable $callback, int $attempts = 3): mixed
    {
        $attempt = 0;

        while (true) {
            try {
                return $this->run($callback);
            } catch (DeadlockException | LockWaitTimeoutException | ConnectionLostException $e) {
                // depth > 0 after the throw means we were the NESTED call of a
                // still-open outer transaction — its replay is the outer's job.
                if ($this->isActive() || ++$attempt >= max(1, $attempts)) {
                    throw $e;
                }

                self::backoff($attempt);
            }
        }
    }

    /**
     * Short jittered pause between transaction replays, so two coroutines
     * that deadlocked against each other do not immediately deadlock again in
     * lockstep. Coroutine-aware: inside Swoole it yields instead of blocking
     * the worker.
     */
    /**
     * Run a raw PDO transaction-control call, mapping a driver failure onto
     * the typed hierarchy so callers keep the same retry semantics they get
     * from the adapters.
     *
     * @template T
     * @param callable(): T $call
     * @return T
     */
    private static function classified(callable $call): mixed
    {
        try {
            return $call();
        } catch (\PDOException $e) {
            throw DriverErrorClassifier::classify($e) ?? $e;
        }
    }

    private static function backoff(int $attempt): void
    {
        $milliseconds = random_int(5, 15) * $attempt;

        if (class_exists(\Swoole\Coroutine::class, false) && \Swoole\Coroutine::getCid() >= 0) {
            \Swoole\Coroutine::sleep($milliseconds / 1000);

            return;
        }

        usleep($milliseconds * 1000);
    }

    /**
     * @template T
     * @param callable(DatabaseAdapterInterface): T $callback
     * @return T
     */
    private function runOuter(callable $callback): mixed
    {
        if ($this->adapter instanceof SqliteAdapter) {
            return $this->runOuterSqlite($callback);
        }

        // Resolve the server version BEFORE taking a connection, never while
        // holding one. On a cold adapter this runs a real detection query, which
        // borrows a connection of its own; asking for it after the pop means a
        // coroutine inside a transaction waits for a SECOND connection while
        // still holding its first. Once `size` coroutines are in that state
        // every pooled connection is held by someone waiting for another and
        // nothing can ever be returned — the pool deadlocks, with the workers
        // idle and the requests simply never answered. The value is cached on
        // the adapter, so this costs a query once and nothing after.
        $serverVersion = $this->adapter->getServerVersion();

        $pdo = $this->pool->pop();
        $this->setActiveConnection($pdo);
        $this->setDepth(1);

        try {
            // beginTransaction() is INSIDE the try: on a stale/dead connection
            // ("server has gone away") it throws, and the finally below must
            // still return the connection to the pool and reset depth/active —
            // otherwise the slot is leaked (the pool shrinks toward exhaustion)
            // and the worker is left with depth=1 pointing at a dead PDO, which
            // corrupts every subsequent transaction into the nested-savepoint
            // branch. A pushed-back dead connection is healed by the pool's
            // ensureAlive() on the next pop().
            $connAdapter = new SingleConnectionAdapter($pdo, $serverVersion);
            $this->setCurrentAdapter($connAdapter);
            try {
                // beginTransaction() runs on the raw PDO, so it bypasses the
                // adapters' classification: without this, a connection that
                // died while idle surfaces as an unclassified \PDOException
                // (2006) that runWithRetry() cannot match, and the request
                // fails instead of replaying on a healthy connection. The
                // pool's idle-ping gate makes this reachable by design — a
                // connection idle under the threshold is served unpinged.
                $pdo->beginTransaction();
            } catch (\PDOException $beginFailure) {
                throw DriverErrorClassifier::classify($beginFailure) ?? $beginFailure;
            }

            $result = $callback($connAdapter);
            $pdo->commit();
        } catch (\Throwable $e) {
            $this->setPendingEvents([]);
            // inTransaction() is INSIDE the try as well: on a severed
            // connection the status check itself throws, and an unguarded one
            // would replace the original callback/commit failure with a
            // cleanup error that says nothing about the real cause.
            try {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (\Throwable) {
                // The connection is cleaned or discarded by the pool's push()
                // transaction hygiene.
            }
            throw $e;
        } finally {
            $this->pool->push($pdo);
            $this->setActiveConnection(null);
            $this->setCurrentAdapter(null);
            $this->setDepth(0);
        }

        // Flush OUTSIDE the try/catch: once commit() has returned, the
        // transaction is durable — a throwing post-commit listener must not
        // surface as a failed write (the caller would retry an already-committed
        // transaction and duplicate it). Flushing after the finally also returns
        // the connection to the pool before listeners run, so slow subscribers
        // never extend the connection hold time.
        $this->flushPendingEvents();

        return $result;
    }

    /**
     * Handle outer transaction for SQLite adapter, serialised per worker.
     *
     * SqliteAdapter owns ONE PDO for the whole worker while the depth /
     * active-connection state is coroutine-local, so two coroutines each see
     * depth 0, each take this OUTER branch, and both call beginTransaction()
     * on the SAME connection. MEASURED with five coroutines that yield
     * mid-transaction: two failed with "There is already an active
     * transaction", two with "There is no active transaction" (one
     * coroutine's commit ended another's transaction), and one of five
     * read-modify-writes survived. The pooled MySQL path in runOuter() above
     * cannot hit this — each outer transaction pops a connection of its own.
     *
     * Of the two possible fixes, a PDO per coroutine is not available here: the
     * SQLite DSN is frequently `sqlite::memory:`, where a second connection is
     * a second, EMPTY database rather than another view of the same one. So the
     * lock it is — one coroutine's BEGIN..COMMIT completes before the next
     * starts. Nesting is unaffected: a nested run() on this coroutine sees
     * depth >= 1 and takes runNested(), never this method, so the lock is
     * never re-entered by its own holder.
     *
     * @template T
     * @param callable(DatabaseAdapterInterface): T $callback
     * @return T
     */
    private function runOuterSqlite(callable $callback): mixed
    {
        if (!$this->adapter instanceof SqliteAdapter) {
            throw new \LogicException('SQLite transactions require the SQLite adapter.');
        }

        // Resolve the server version BEFORE taking the lock, never while
        // holding it: on a cold adapter this runs a detection query, and doing
        // it under the mutex makes every other coroutine wait for work that has
        // nothing to do with their transaction.
        $serverVersion = $this->adapter->getServerVersion();

        $mutex = $this->acquireSqliteTxMutex();

        // Declared before the try so the catch below can tell "the connection
        // never opened" from "it opened and the work failed". getPdo() lazily
        // creates the PDO, and it has to run INSIDE the try or a failure there
        // would skip the finally and leak the lock — permanently, since nothing
        // else ever pushes the token back.
        $pdo = null;

        try {
            $pdo = $this->adapter->getPdo();
            $this->setActiveConnection($pdo);
            $this->setDepth(1);

            $connAdapter = new SingleConnectionAdapter($pdo, $serverVersion);
            $this->setCurrentAdapter($connAdapter);

            // INSIDE the try, like the pooled path: a beginTransaction() that
            // throws out here would skip the finally, leaving depth=1 and an
            // active connection behind, so the NEXT run() on this coroutine
            // would take the nested-savepoint branch against a transaction
            // that was never opened.
            try {
                $pdo->beginTransaction();
            } catch (\PDOException $beginFailure) {
                throw DriverErrorClassifier::classify($beginFailure) ?? $beginFailure;
            }

            $result = $callback($connAdapter);
            $pdo->commit();
        } catch (\Throwable $e) {
            $this->setPendingEvents([]);
            // See runOuter(): the status check is guarded too, so a severed
            // connection cannot mask the original failure.
            try {
                if ($pdo instanceof \PDO && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (\Throwable) {
                // Nothing actionable here.
            }
            throw $e;
        } finally {
            $this->setActiveConnection(null);
            $this->setCurrentAdapter(null);
            $this->setDepth(0);
            // Released LAST, after this coroutine's state is cleared. push()
            // can resume a waiter immediately, and letting the next BEGIN start
            // while this transaction's adapter and depth are still published
            // hands it a half-torn-down transaction to reason about.
            $mutex?->push(true);
        }

        // See runOuter(): post-commit flush must never report a committed
        // transaction as failed.
        $this->flushPendingEvents();

        return $result;
    }

    /**
     * Acquire the intra-worker SQLite transaction lock, returning the Channel to
     * release on — or null when there is no coroutine to interleave with (the
     * CLI path, where this whole hazard cannot occur and Channel methods are
     * illegal anyway).
     *
     * Lazy-created inside the coroutine: `new Channel(1)` and the seeding
     * `push()` do not yield, so two coroutines cannot both initialise it, and
     * the loser parks in `pop()` until the holder pushes the token back.
     *
     * The wait is bounded. An unbounded `pop()` would reproduce, one layer up,
     * the defect ConnectionPool records in DEFAULT_POP_TIMEOUT_SECONDS: a
     * coroutine parked forever answers no request and says nothing about what
     * it is waiting for. The realistic way to exhaust this budget is a
     * transaction opened from a coroutine spawned INSIDE another transaction,
     * which no amount of waiting resolves — so it fails loudly, naming itself.
     */
    private function acquireSqliteTxMutex(): ?\Swoole\Coroutine\Channel
    {
        if (!self::inCoroutine()) {
            return null;
        }

        if ($this->sqliteTxMutex === null) {
            $this->sqliteTxMutex = new \Swoole\Coroutine\Channel(1);
            $this->sqliteTxMutex->push(true);
        }

        if ($this->sqliteTxMutex->pop(self::SQLITE_TX_LOCK_TIMEOUT_SECONDS) === false) {
            throw new TransactionLockTimeoutException(sprintf(
                'Timed out after %.1fs waiting for the SQLite transaction lock on connection "%s". '
                . 'SQLite shares one connection per worker, so outer transactions are serialised; '
                . 'a transaction started from a coroutine spawned inside another transaction waits '
                . 'for a lock its own caller holds and can never obtain it.',
                self::SQLITE_TX_LOCK_TIMEOUT_SECONDS,
                $this->connectionName,
            ));
        }

        return $this->sqliteTxMutex;
    }

    private static function inCoroutine(): bool
    {
        return class_exists(\Swoole\Coroutine::class, false) && \Swoole\Coroutine::getCid() > 0;
    }

    /**
     * @template T
     * @param callable(DatabaseAdapterInterface): T $callback
     * @return T
     */
    private function runNested(callable $callback): mixed
    {
        $pdo = $this->activeConnection();
        if (!$pdo instanceof \PDO) {
            throw new \LogicException('Nested transaction requested without an active PDO connection.');
        }

        $depth = $this->depth() + 1;
        $this->setDepth($depth);
        $savepointName = 'sp_' . $depth;

        $connAdapter = new SingleConnectionAdapter($pdo, $this->adapter->getServerVersion());

        // Transaction-control statements go through the classifier too. They
        // run as raw PDO calls, so without this a connection lost while
        // opening or releasing a savepoint would surface as an untyped
        // PDOException that runWithRetry() cannot match — the retry taxonomy
        // would have a hole exactly on the nesting path.
        self::classified(fn () => $pdo->exec("SAVEPOINT {$savepointName}"));

        try {
            $result = $callback($connAdapter);
            self::classified(fn () => $pdo->exec("RELEASE SAVEPOINT {$savepointName}"));

            return $result;
        } catch (\Throwable $e) {
            // Both statements are guarded: on a deadlock (1213) InnoDB has
            // already rolled the WHOLE transaction back and destroyed every
            // savepoint, so ROLLBACK TO throws 1305 "SAVEPOINT does not exist"
            // — from inside this catch, replacing the DeadlockException that
            // runWithRetry() matches on. An unguarded rollback here silently
            // disables the entire retry machinery. ROLLBACK TO also leaves the
            // savepoint in place, so RELEASE keeps a long outer transaction
            // from accumulating them.
            try {
                $pdo->exec("ROLLBACK TO SAVEPOINT {$savepointName}");
                $pdo->exec("RELEASE SAVEPOINT {$savepointName}");
            } catch (\Throwable) {
                // Nothing actionable: either the transaction is already gone
                // (the original exception says why) or the connection died —
                // the pool's push() hygiene handles the connection.
            }
            throw $e;
        } finally {
            $this->setDepth($this->depth() - 1);
        }
    }

    private function flushPendingEvents(): void
    {
        $events = $this->pendingEvents();
        $this->setPendingEvents([]);

        if ($this->eventDispatcher === null) {
            return;
        }

        foreach ($events as $event) {
            try {
                $this->eventDispatcher->dispatch($event);
            } catch (\Throwable $e) {
                // Swallowed per event, but never silently: the transaction is
                // already committed and these are best-effort invalidation
                // signals (same contract as
                // AggregateWriteEngine::dispatchResourceChanged), so a throwing
                // listener must neither fail the write nor starve the remaining
                // events. It must still be visible — before the commit-gating
                // buffer existed these errors propagated, and a broken
                // ui.invalidate/SSE chain that vanishes without a trace is a
                // debugging dead end.
                StaticLoggerBridge::error('orm', 'A post-commit event listener failed; the transaction is committed and the signal is lost.', [
                    'event' => $event::class,
                    'connection' => $this->connectionName,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}

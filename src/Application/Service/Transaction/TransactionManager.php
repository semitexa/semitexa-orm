<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service\Transaction;

use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Core\Event\EventDispatcherInterface;
use Semitexa\Orm\Adapter\ConnectionPoolInterface;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\SqliteAdapter;

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
     */
    private const KEY_ACTIVE_CONNECTION = 'orm.tx.activeConnection';
    private const KEY_DEPTH = 'orm.tx.depth';
    private const KEY_PENDING_EVENTS = 'orm.tx.pendingEvents';

    public function __construct(
        private readonly ConnectionPoolInterface $pool,
        private readonly DatabaseAdapterInterface $adapter,
        private ?EventDispatcherInterface $eventDispatcher = null,
    ) {}

    /** Active PDO connection for the current coroutine's (outermost) transaction, null when idle. */
    private function activeConnection(): ?\PDO
    {
        return CoroutineLocal::get(self::KEY_ACTIVE_CONNECTION);
    }

    private function setActiveConnection(?\PDO $pdo): void
    {
        CoroutineLocal::set(self::KEY_ACTIVE_CONNECTION, $pdo);
    }

    /** Nesting depth for the current coroutine: 0 = no transaction, 1 = outer BEGIN, 2+ = savepoints. */
    private function depth(): int
    {
        return (int) CoroutineLocal::get(self::KEY_DEPTH, 0);
    }

    private function setDepth(int $depth): void
    {
        CoroutineLocal::set(self::KEY_DEPTH, $depth);
    }

    /** @return object[] Buffered events for the current coroutine, dispatched after successful outer commit. */
    private function pendingEvents(): array
    {
        return CoroutineLocal::get(self::KEY_PENDING_EVENTS, []);
    }

    /** @param object[] $events */
    private function setPendingEvents(array $events): void
    {
        CoroutineLocal::set(self::KEY_PENDING_EVENTS, $events);
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
     * @template T
     * @param callable(DatabaseAdapterInterface): T $callback
     * @return T
     */
    private function runOuter(callable $callback): mixed
    {
        if ($this->adapter instanceof SqliteAdapter) {
            return $this->runOuterSqlite($callback);
        }

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
            $connAdapter = new SingleConnectionAdapter($pdo, $this->adapter->getServerVersion());
            $pdo->beginTransaction();

            $result = $callback($connAdapter);
            $pdo->commit();

            $this->flushPendingEvents();

            return $result;
        } catch (\Throwable $e) {
            $this->setPendingEvents([]);
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            $this->pool->push($pdo);
            $this->setActiveConnection(null);
            $this->setDepth(0);
        }
    }

    /**
     * Handle outer transaction for SQLite adapter.
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

        $pdo = $this->adapter->getPdo();
        $this->setActiveConnection($pdo);
        $this->setDepth(1);

        $connAdapter = new SingleConnectionAdapter($pdo, $this->adapter->getServerVersion());
        $pdo->beginTransaction();

        try {
            $result = $callback($connAdapter);
            $pdo->commit();

            $this->flushPendingEvents();

            return $result;
        } catch (\Throwable $e) {
            $this->setPendingEvents([]);
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            $this->setActiveConnection(null);
            $this->setDepth(0);
        }
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
        $pdo->exec("SAVEPOINT {$savepointName}");

        try {
            $result = $callback($connAdapter);
            $pdo->exec("RELEASE SAVEPOINT {$savepointName}");
            return $result;
        } catch (\Throwable $e) {
            $pdo->exec("ROLLBACK TO SAVEPOINT {$savepointName}");
            throw $e;
        } finally {
            $this->setDepth($this->depth() - 1);
        }
    }

    private function flushPendingEvents(): void
    {
        if ($this->eventDispatcher !== null) {
            $events = $this->pendingEvents();
            $this->setPendingEvents([]);
            foreach ($events as $event) {
                $this->eventDispatcher->dispatch($event);
            }
        } else {
            $this->setPendingEvents([]);
        }
    }
}

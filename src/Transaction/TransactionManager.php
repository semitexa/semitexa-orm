<?php

declare(strict_types=1);

namespace Semitexa\Orm\Transaction;

use Semitexa\Orm\Adapter\ConnectionPoolInterface;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;

class TransactionManager
{
    /** Active PDO connection for the current (outermost) transaction, null when idle. */
    private ?\PDO $activeConnection = null;

    /** Nesting depth: 0 = no transaction, 1 = outer BEGIN, 2+ = savepoints. */
    private int $depth = 0;

    public function __construct(
        private readonly ConnectionPoolInterface $pool,
        private readonly DatabaseAdapterInterface $adapter,
    ) {}

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
        if ($this->depth === 0) {
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
        $pdo = $this->pool->pop();
        $this->activeConnection = $pdo;
        $this->depth = 1;

        $connAdapter = new SingleConnectionAdapter($pdo, $this->adapter->getServerVersion());
        $pdo->beginTransaction();

        try {
            $result = $callback($connAdapter);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            $this->pool->push($pdo);
            $this->activeConnection = null;
            $this->depth = 0;
        }
    }

    /**
     * @template T
     * @param callable(DatabaseAdapterInterface): T $callback
     * @return T
     */
    private function runNested(callable $callback): mixed
    {
        $pdo = $this->activeConnection;
        $this->depth++;
        $savepointName = 'sp_' . $this->depth;

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
            $this->depth--;
        }
    }
}

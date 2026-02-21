<?php

declare(strict_types=1);

namespace Semitexa\Orm\Transaction;

use Semitexa\Orm\Adapter\ConnectionPool;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;

class TransactionManager
{
    public function __construct(
        private readonly ConnectionPool $pool,
        private readonly DatabaseAdapterInterface $adapter,
    ) {}

    /**
     * Execute a callable within a database transaction.
     *
     * Takes a connection from the pool, begins a transaction, and passes
     * a single-connection DatabaseAdapterInterface to the callable.
     * Repositories inside should use `$repo->useConnection($conn)` to bind
     * all their operations to this transactional connection.
     *
     * On success: COMMIT, return connection to pool.
     * On exception: ROLLBACK, return connection to pool, re-throw.
     *
     * @template T
     * @param callable(DatabaseAdapterInterface): T $callback
     * @return T
     */
    public function run(callable $callback): mixed
    {
        $pdo = $this->pool->pop();

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
        }
    }
}

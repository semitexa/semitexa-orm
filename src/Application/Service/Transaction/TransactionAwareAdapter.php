<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service\Transaction;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\QueryResult;
use Semitexa\Orm\Adapter\ServerCapability;

/**
 * Routes each query to the current coroutine's transaction connection when a
 * transaction is active on this manager's connection, and to the pooled
 * adapter otherwise.
 *
 * Why: the repository/read path holds the pooled adapter, where every call
 * pops a DIFFERENT connection. Inside TransactionManager::run() that has two
 * failure modes: reads do not see the transaction's own uncommitted writes
 * (validation/uniqueness checks inside a tx observe pre-commit state), and a
 * coroutine holding the transaction connection pops a SECOND one — `size`
 * such coroutines exhaust the pool while each waits for a connection someone
 * else is holding.
 *
 * Both dependencies are resolved lazily per call: OrmManager's pool/adapter
 * self-heal (SingleConnectionPool → ConnectionPool upgrade) rebuilds them, and
 * a captured instance would pin the stale pre-upgrade object.
 *
 * This decorator is deliberately NOT returned by OrmManager::getAdapter():
 * consumers (semitexa-update, SyncEngine, TransactionManager itself) branch on
 * `instanceof MysqlAdapter/SqliteAdapter` for dialect decisions, and wrapping
 * the adapter there would silently disable those branches. It is wired only
 * into the repository read path, which never dialect-branches on the adapter.
 */
final class TransactionAwareAdapter implements DatabaseAdapterInterface
{
    /**
     * @param \Closure(): DatabaseAdapterInterface $adapterResolver
     * @param \Closure(): ?TransactionManager $transactionsResolver
     */
    public function __construct(
        private readonly \Closure $adapterResolver,
        private readonly \Closure $transactionsResolver,
    ) {}

    public function supports(ServerCapability $capability): bool
    {
        return ($this->adapterResolver)()->supports($capability);
    }

    public function getServerVersion(): string
    {
        // Always the pooled adapter: it memoizes the version and, when cold,
        // borrows its own connection — routing this through an open transaction
        // would be harmless, but keeping it on one path keeps the tx adapter's
        // lifetime strictly inside TransactionManager::run().
        return ($this->adapterResolver)()->getServerVersion();
    }

    public function execute(string $sql, array $params = []): QueryResult
    {
        return $this->target()->execute($sql, $params);
    }

    public function query(string $sql): QueryResult
    {
        return $this->target()->query($sql);
    }

    public function lastInsertId(): string
    {
        return $this->target()->lastInsertId();
    }

    private function target(): DatabaseAdapterInterface
    {
        $transactions = ($this->transactionsResolver)();

        return $transactions?->currentAdapter() ?? ($this->adapterResolver)();
    }
}

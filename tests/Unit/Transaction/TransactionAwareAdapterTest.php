<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Transaction;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\ConnectionPoolInterface;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\QueryResult;
use Semitexa\Orm\Adapter\ServerCapability;
use Semitexa\Orm\Application\Service\Transaction\TransactionAwareAdapter;
use Semitexa\Orm\Application\Service\Transaction\TransactionManager;

/**
 * The repository read path holds the pooled adapter, where every call pops a
 * DIFFERENT connection — so inside TransactionManager::run() reads would not
 * see the transaction's own uncommitted writes, and the coroutine would
 * borrow a second connection while holding the first (the pool-exhaustion
 * shape). TransactionAwareAdapter pins the fix: inside run() every query
 * routes to the transaction's own connection; outside, to the pooled adapter.
 */
final class TransactionAwareAdapterTest extends TestCase
{
    #[Test]
    public function reads_inside_a_transaction_see_its_uncommitted_writes(): void
    {
        $pool = new SingleSqlitePdoPool();
        $pooledAdapter = new RecordingIdleAdapter();
        $manager = new TransactionManager($pool, $pooledAdapter);
        $adapter = new TransactionAwareAdapter(
            static fn (): DatabaseAdapterInterface => $pooledAdapter,
            static fn (): TransactionManager => $manager,
        );

        $seenInsideTx = null;
        $manager->run(function () use ($adapter, &$seenInsideTx) {
            $adapter->execute('CREATE TABLE tx_probe (id INTEGER PRIMARY KEY)');
            $adapter->execute('INSERT INTO tx_probe (id) VALUES (1)');
            $seenInsideTx = (int) $adapter->execute('SELECT COUNT(*) AS c FROM tx_probe')->fetchColumn();

            return null;
        });

        self::assertSame(1, $seenInsideTx, 'a read inside the transaction must see its own uncommitted write');
        self::assertSame(
            [],
            $pooledAdapter->statements,
            'inside a transaction, no query may borrow a pooled connection',
        );
    }

    #[Test]
    public function reads_outside_a_transaction_use_the_pooled_adapter(): void
    {
        $pool = new SingleSqlitePdoPool();
        $pooledAdapter = new RecordingIdleAdapter();
        $manager = new TransactionManager($pool, $pooledAdapter);
        $adapter = new TransactionAwareAdapter(
            static fn (): DatabaseAdapterInterface => $pooledAdapter,
            static fn (): TransactionManager => $manager,
        );

        $adapter->execute('SELECT 1');

        self::assertSame(['SELECT 1'], $pooledAdapter->statements);
        self::assertSame(0, $pool->popCount, 'no transaction means no dedicated connection');
    }

    #[Test]
    public function the_transaction_route_resets_after_commit_and_after_rollback(): void
    {
        $pool = new SingleSqlitePdoPool();
        $pooledAdapter = new RecordingIdleAdapter();
        $manager = new TransactionManager($pool, $pooledAdapter);
        $adapter = new TransactionAwareAdapter(
            static fn (): DatabaseAdapterInterface => $pooledAdapter,
            static fn (): TransactionManager => $manager,
        );

        $manager->run(static fn () => null);
        $adapter->execute('SELECT after_commit');

        try {
            $manager->run(static function (): void {
                throw new \RuntimeException('force rollback');
            });
        } catch (\RuntimeException) {
            // expected
        }
        $adapter->execute('SELECT after_rollback');

        self::assertSame(
            ['SELECT after_commit', 'SELECT after_rollback'],
            $pooledAdapter->statements,
            'once the transaction is over, queries must route to the pooled adapter again',
        );
    }
}

/** A pool over one real sqlite PDO, counting checkouts. */
final class SingleSqlitePdoPool implements ConnectionPoolInterface
{
    public int $popCount = 0;

    private ?\PDO $pdo = null;

    public function pop(?float $timeout = null): \PDO
    {
        $this->popCount++;

        if ($this->pdo === null) {
            $this->pdo = new \PDO('sqlite::memory:');
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        }

        return $this->pdo;
    }

    public function push(\PDO $connection): void {}
    public function close(): void {}
    public function getSize(): int { return 1; }
    public function getAvailable(): int { return 1; }
    public function switchTo(string $tenantId): void {}
}

/** The pooled adapter: non-SQLite (so run() takes the pooled path) and records every statement. */
final class RecordingIdleAdapter implements DatabaseAdapterInterface
{
    /** @var string[] */
    public array $statements = [];

    public function supports(ServerCapability $capability): bool { return true; }
    public function getServerVersion(): string { return '8.0.0'; }

    public function execute(string $sql, array $params = []): QueryResult
    {
        $this->statements[] = $sql;

        return new QueryResult();
    }

    public function query(string $sql): QueryResult
    {
        $this->statements[] = $sql;

        return new QueryResult();
    }

    public function lastInsertId(): string { return '0'; }
}

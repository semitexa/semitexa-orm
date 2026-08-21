<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Transaction;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\ConnectionPoolInterface;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\QueryResult;
use Semitexa\Orm\Adapter\ServerCapability;
use Semitexa\Orm\Application\Service\Transaction\TransactionManager;

/**
 * Every named connection has its own TransactionManager (one per OrmManager,
 * see ConnectionRegistry), but the transaction state lives in CoroutineLocal
 * under string keys. Were those keys shared across managers, a transaction
 * opened on connection B while connection A's transaction is active in the
 * SAME coroutine would read A's depth, take the nested-savepoint branch, and
 * run B's writes on A's PDO — committing them to the wrong database. This
 * pins the per-connection key namespacing: an inner transaction on a second
 * named connection must pop its own connection from its own pool.
 */
final class TransactionManagerPerConnectionIsolationTest extends TestCase
{
    #[Test]
    public function a_transaction_on_a_second_named_connection_gets_its_own_pdo(): void
    {
        $poolA = new CountingPerPopPool();
        $poolB = new CountingPerPopPool();

        $managerA = new TransactionManager($poolA, new FakeNonSqliteAdapter(), connectionName: 'default');
        $managerB = new TransactionManager($poolB, new FakeNonSqliteAdapter(), connectionName: 'project_graph');

        $eventA = (object) ['id' => 'A'];
        $eventB = (object) ['id' => 'B'];
        $pendingSeenInsideB = null;

        $managerA->run(function () use ($managerA, $managerB, $eventA, $eventB, &$pendingSeenInsideB) {
            $managerA->bufferEvent($eventA);

            $managerB->run(function () use ($managerB, $eventB, &$pendingSeenInsideB) {
                $managerB->bufferEvent($eventB);
                // With shared keys, B's buffer would already contain A's event.
                $pendingSeenInsideB = $managerB->getPendingEvents();

                return null;
            });

            return null;
        });

        // With shared CoroutineLocal keys, managerB->run() sees depth=1 and takes
        // the nested-savepoint branch on A's PDO: poolB is never popped.
        self::assertSame(1, $poolB->popCount, 'the inner transaction on the second connection must pop from ITS OWN pool');
        self::assertSame(1, $poolA->popCount, 'the outer transaction must have popped exactly one connection from pool A');
        self::assertNotSame(
            $poolA->popped[0],
            $poolB->popped[0],
            'the two named connections must not share a PDO',
        );

        // Pending events must not cross-leak between connections.
        self::assertSame([$eventB], $pendingSeenInsideB, "connection B's buffer must hold only its own event");

        self::assertFalse($managerA->isActive(), 'no transaction state must linger on connection A');
        self::assertFalse($managerB->isActive(), 'no transaction state must linger on connection B');
    }
}

/** Hands out a fresh real PDO on every pop and counts checkouts. */
final class CountingPerPopPool implements ConnectionPoolInterface
{
    public int $popCount = 0;

    /** @var \PDO[] */
    public array $popped = [];

    public function pop(?float $timeout = null): \PDO
    {
        $this->popCount++;
        $pdo = new \PDO('sqlite::memory:');
        $this->popped[] = $pdo;

        return $pdo;
    }

    public function push(\PDO $connection): void {}
    public function close(): void {}
    public function getSize(): int { return 2; }
    public function getAvailable(): int { return 2; }
    public function switchTo(string $tenantId): void {}
}

/** A non-SQLite adapter so run() takes the pooled outer-transaction path. */
final class FakeNonSqliteAdapter implements DatabaseAdapterInterface
{
    public function supports(ServerCapability $capability): bool { return true; }
    public function getServerVersion(): string { return '8.0.0'; }
    public function execute(string $sql, array $params = []): QueryResult { return new QueryResult(); }
    public function query(string $sql): QueryResult { return new QueryResult(); }
    public function lastInsertId(): string { return '0'; }
}

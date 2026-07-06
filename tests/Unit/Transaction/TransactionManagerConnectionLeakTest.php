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
 * runOuter() pops a pooled connection and issues BEGIN. If BEGIN throws — a
 * stale/dead PDO, the "MySQL server has gone away" / reconnect-storm case — the
 * connection must STILL be returned to the pool and depth/active reset. The old
 * code issued beginTransaction() outside the try/finally, so a throw there
 * leaked the connection out of the pool (shrinking it toward exhaustion) and
 * left the worker at depth=1 on a dead PDO, corrupting every later transaction.
 */
final class TransactionManagerConnectionLeakTest extends TestCase
{
    #[Test]
    public function a_begin_failure_still_returns_the_connection_and_resets_state(): void
    {
        $pool = new RecordingPool(new ThrowingBeginPdo());
        $manager = new TransactionManager($pool, new FakeMysqlAdapter());

        try {
            $manager->run(static fn () => 'never reached');
            self::fail('the BEGIN failure must propagate');
        } catch (\PDOException $e) {
            self::assertStringContainsString('gone away', $e->getMessage());
        }

        self::assertSame(1, $pool->popCount);
        self::assertSame(1, $pool->pushCount, 'the connection must be returned to the pool, not leaked');
        self::assertFalse($manager->isActive(), 'depth/active must be reset after the failure');
    }

    #[Test]
    public function the_manager_is_reusable_after_a_begin_failure(): void
    {
        // A leaked connection + a stuck depth=1 would corrupt the NEXT transaction
        // (it would take the nested-savepoint branch on a dead PDO). After the fix
        // the manager is clean, so a following healthy transaction just works.
        $pool = new RecordingPool(new ThrowingBeginPdo());
        $manager = new TransactionManager($pool, new FakeMysqlAdapter());

        try {
            $manager->run(static fn () => null);
        } catch (\PDOException) {
            // expected
        }

        $pool->handOut(new \PDO('sqlite::memory:')); // a healthy connection next
        $value = $manager->run(static fn () => 'ok');

        self::assertSame('ok', $value);
        self::assertSame(2, $pool->pushCount, 'the second, healthy connection is also returned');
        self::assertFalse($manager->isActive());
    }
}

final class RecordingPool implements ConnectionPoolInterface
{
    public int $popCount = 0;
    public int $pushCount = 0;

    public function __construct(private \PDO $connection) {}

    public function handOut(\PDO $connection): void
    {
        $this->connection = $connection;
    }

    public function pop(float $timeout = -1): \PDO
    {
        $this->popCount++;
        return $this->connection;
    }

    public function push(\PDO $connection): void
    {
        $this->pushCount++;
    }

    public function close(): void {}
    public function getSize(): int { return 1; }
    public function getAvailable(): int { return 1; }
    public function switchTo(string $tenantId): void {}
}

final class ThrowingBeginPdo extends \PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
    }

    public function beginTransaction(): bool
    {
        throw new \PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
    }
}

final class FakeMysqlAdapter implements DatabaseAdapterInterface
{
    public function supports(ServerCapability $capability): bool { return true; }
    public function getServerVersion(): string { return '8.0.0'; }
    public function execute(string $sql, array $params = []): QueryResult { return new QueryResult(); }
    public function query(string $sql): QueryResult { return new QueryResult(); }
    public function lastInsertId(): string { return '0'; }
}

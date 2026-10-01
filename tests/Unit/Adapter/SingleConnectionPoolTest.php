<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\SingleConnectionPool;

final class SingleConnectionPoolTest extends TestCase
{
    #[Test]
    public function it_reuses_alive_connection(): void
    {
        $created = 0;
        $statement = $this->createMock(\PDOStatement::class);
        $pool = new SingleConnectionPool(static function () use (&$created, $statement): \PDO {
            ++$created;

            return new HealthyPdo($statement);
        });

        $first = $pool->pop();
        $pool->push($first);

        $second = $pool->pop();

        self::assertSame($first, $second);
        self::assertSame(1, $created);
        self::assertSame(1, $second->queries);
    }

    #[Test]
    public function it_drops_a_foreign_connection_pushed_over_the_owned_one(): void
    {
        $statement = $this->createMock(\PDOStatement::class);
        $owned   = new HealthyPdo($statement);
        $foreign = new HealthyPdo($statement);

        $pool = new SingleConnectionPool(static fn (): \PDO => $owned);

        $first = $pool->pop();
        self::assertSame($owned, $first);

        // A connection the pool never handed out must not overwrite the cached
        // one — the extra is dropped (GC-closed), not stored.
        $pool->push($foreign);

        $second = $pool->pop();
        self::assertSame($owned, $second);
        self::assertNotSame($foreign, $second);
    }

    #[Test]
    public function it_replaces_stale_connection(): void
    {
        $statement = $this->createMock(\PDOStatement::class);
        $connections = [
            new StalePdo(),
            new HealthyPdo($statement),
        ];

        $pool = new SingleConnectionPool(static function () use (&$connections): \PDO {
            $connection = array_shift($connections);

            if (! $connection instanceof \PDO) {
                throw new \RuntimeException('No test connection available.');
            }

            return $connection;
        });

        $stale = $pool->pop();
        $pool->push($stale);

        $fresh = $pool->pop();

        self::assertNotSame($stale, $fresh);
        self::assertInstanceOf(HealthyPdo::class, $fresh);
    }

    #[Test]
    public function it_replaces_connection_when_health_check_returns_false(): void
    {
        $statement = $this->createMock(\PDOStatement::class);
        $connections = [
            new FalseHealthCheckPdo(),
            new HealthyPdo($statement),
        ];

        $pool = new SingleConnectionPool(static function () use (&$connections): \PDO {
            $connection = array_shift($connections);

            if (! $connection instanceof \PDO) {
                throw new \RuntimeException('No test connection available.');
            }

            return $connection;
        });

        $stale = $pool->pop();
        $pool->push($stale);

        $fresh = $pool->pop();

        self::assertNotSame($stale, $fresh);
        self::assertInstanceOf(HealthyPdo::class, $fresh);
    }

    #[Test]
    public function a_nested_borrow_does_not_end_the_outer_transaction(): void
    {
        // TransactionManager holds the connection for a transaction; a read
        // inside it (OrmManager::getAdapter()->execute()) pops the same PDO and
        // pushes it back. That push used to roll the outer transaction back.
        $pool = new SingleConnectionPool(static fn (): \PDO => new \PDO('sqlite::memory:'));

        $outer = $pool->pop();
        $outer->exec('CREATE TABLE t (id INTEGER)');
        $outer->beginTransaction();
        $outer->exec('INSERT INTO t VALUES (1)');

        $inner = $pool->pop();
        self::assertSame($outer, $inner);
        $inner->query('SELECT COUNT(*) FROM t');
        $pool->push($inner);

        self::assertTrue($outer->inTransaction(), 'the inner push ended the outer transaction');
        $outer->commit();
        $pool->push($outer);

        self::assertSame(1, (int) $pool->pop()->query('SELECT COUNT(*) FROM t')->fetchColumn());
    }

    #[Test]
    public function the_outermost_push_still_rolls_back_a_leaked_transaction(): void
    {
        $pool = new SingleConnectionPool(static fn (): \PDO => new \PDO('sqlite::memory:'));

        $connection = $pool->pop();
        $connection->exec('CREATE TABLE t (id INTEGER)');
        $connection->beginTransaction();
        $connection->exec('INSERT INTO t VALUES (1)');
        $pool->push($connection);

        self::assertFalse($connection->inTransaction());
        self::assertSame(0, (int) $pool->pop()->query('SELECT COUNT(*) FROM t')->fetchColumn());
    }
}

final class HealthyPdo extends \PDO
{
    public int $queries = 0;
    private \PDOStatement $statement;

    public function __construct(\PDOStatement $statement)
    {
        $this->statement = $statement;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        ++$this->queries;

        return $this->statement;
    }
}

final class StalePdo extends \PDO
{
    public function __construct()
    {
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        throw new \PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
    }
}

final class FalseHealthCheckPdo extends \PDO
{
    public function __construct()
    {
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        return false;
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\ConnectionPoolInterface;
use Semitexa\Orm\Adapter\MysqlAdapter;
use Semitexa\Orm\Exception\ConnectionLostException;

/**
 * When the server drops a pooled connection mid-query, the adapter must
 * (a) surface a typed ConnectionLostException instead of a raw PDOException,
 * (b) NOT re-queue the dead connection, and (c) transparently replay the
 * statement once on a fresh connection — but ONLY for read-only statements:
 * a write may have been applied before the drop, and replaying it would
 * duplicate the effect.
 */
final class MysqlAdapterConnectionLostRetryTest extends TestCase
{
    #[Test]
    public function a_read_only_statement_is_replayed_once_on_a_fresh_connection(): void
    {
        $pool = new DeadThenAlivePool();
        $adapter = new MysqlAdapter($pool);

        $result = $adapter->execute('SELECT 42 AS answer');

        self::assertSame('42', (string) $result->fetchColumn(), 'the replay on a fresh connection must succeed');
        self::assertSame(2, $pool->popCount, 'the dead first attempt plus one replay');
        self::assertNotContains(
            $pool->deadConnection,
            $pool->pushed,
            'the dead connection must never be re-queued',
        );
    }

    #[Test]
    public function a_write_is_not_replayed_and_surfaces_the_typed_exception(): void
    {
        $pool = new DeadThenAlivePool();
        $adapter = new MysqlAdapter($pool);

        try {
            $adapter->execute('INSERT INTO t (id) VALUES (1)');
            self::fail('the connection loss must propagate for a write');
        } catch (ConnectionLostException $e) {
            self::assertSame(2006, $e->driverCode);
            self::assertTrue($e->isTransient());
        }

        self::assertSame(1, $pool->popCount, 'a write must not be transparently replayed');
        self::assertNotContains($pool->deadConnection, $pool->pushed);
    }
}

/**
 * First pop() hands out a PDO whose prepare() fails with MySQL 2006; every
 * later pop() hands out a real sqlite connection. Records pushes so the test
 * can assert the dead connection never re-enters the pool.
 */
final class DeadThenAlivePool implements ConnectionPoolInterface
{
    public int $popCount = 0;

    public ?\PDO $deadConnection = null;

    /** @var \PDO[] */
    public array $pushed = [];

    public function pop(?float $timeout = null): \PDO
    {
        $this->popCount++;

        if ($this->popCount === 1) {
            return $this->deadConnection = new GoneAwayPdo();
        }

        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    public function push(\PDO $connection): void
    {
        $this->pushed[] = $connection;
    }

    public function close(): void {}
    public function getSize(): int { return 2; }
    public function getAvailable(): int { return 0; }
    public function switchTo(string $tenantId): void {}
}

/** A PDO whose prepare() always fails like a dropped MySQL connection. */
final class GoneAwayPdo extends \PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
    }

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        $e = new \PDOException('MySQL server has gone away');
        $e->errorInfo = ['HY000', 2006, 'MySQL server has gone away'];
        throw $e;
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\ConnectionPool;
use Semitexa\Orm\Adapter\SingleConnectionPool;
use Swoole\Coroutine;

/**
 * A connection must never re-enter a pool mid-transaction: the next coroutine
 * would inherit the open transaction and its writes would be committed or
 * rolled back by whoever touches that connection later — silent cross-request
 * corruption. push() rolls the leftover transaction back; if even the rollback
 * fails (dead connection) the connection is discarded, never re-queued.
 */
final class ConnectionPoolPushHygieneTest extends TestCase
{
    #[Test]
    public function a_pushed_connection_with_an_open_transaction_is_rolled_back(): void
    {
        if (!class_exists(\Swoole\Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }

        Coroutine\run(function () {
            $pool = new ConnectionPool(1, static fn (): \PDO => new \PDO('sqlite::memory:'));

            $pdo = $pool->pop();
            $pdo->exec('CREATE TABLE hygiene_probe (id INTEGER PRIMARY KEY)');
            $pdo->beginTransaction();
            $pdo->exec('INSERT INTO hygiene_probe (id) VALUES (1)');
            self::assertTrue($pdo->inTransaction(), 'Precondition: the transaction is open at push time.');

            $pool->push($pdo);

            $again = $pool->pop();
            self::assertSame($pdo, $again, 'a size-1 pool must hand the same connection back');
            self::assertFalse($again->inTransaction(), 'the leftover transaction must have been rolled back');
            $count = (int) $again->query('SELECT COUNT(*) FROM hygiene_probe')->fetchColumn();
            self::assertSame(0, $count, 'the uncommitted write must be gone');
            $pool->push($again);

            $pool->close();
        });
    }

    #[Test]
    public function a_connection_whose_rollback_fails_is_discarded_not_requeued(): void
    {
        if (!class_exists(\Swoole\Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }

        Coroutine\run(function () {
            $minted = 0;
            $pool = new ConnectionPool(1, static function () use (&$minted): \PDO {
                ++$minted;
                if ($minted === 1) {
                    return new DeadOnRollbackPdo();
                }

                return new \PDO('sqlite::memory:');
            });

            $dead = $pool->pop();
            self::assertInstanceOf(DeadOnRollbackPdo::class, $dead);
            $dead->beginTransaction();

            // rollBack() throws → the connection must be discarded and its slot
            // released, so the pool can mint a fresh replacement.
            $pool->push($dead);
            self::assertSame(0, $pool->getAvailable(), 'the poisoned connection must not be re-queued');

            $fresh = $pool->pop();
            self::assertNotSame($dead, $fresh, 'the next pop must get a fresh connection, not the poisoned one');
            self::assertSame(2, $minted, 'the released slot must allow minting a replacement');
            $pool->push($fresh);

            $pool->close();
        });
    }

    #[Test]
    public function single_connection_pool_rolls_back_before_recaching(): void
    {
        $pool = new SingleConnectionPool(static fn (): \PDO => new \PDO('sqlite::memory:'));

        $pdo = $pool->pop();
        $pdo->beginTransaction();
        $pool->push($pdo);

        self::assertFalse($pdo->inTransaction(), 'push() must roll back the leftover transaction');
        self::assertSame($pdo, $pool->pop(), 'the cleaned connection is safe to reuse');
    }

    #[Test]
    public function single_connection_pool_drops_a_connection_whose_rollback_fails(): void
    {
        $minted = 0;
        $pool = new SingleConnectionPool(static function () use (&$minted): \PDO {
            ++$minted;
            if ($minted === 1) {
                return new DeadOnRollbackPdo();
            }

            return new \PDO('sqlite::memory:');
        });

        $dead = $pool->pop();
        $dead->beginTransaction();
        $pool->push($dead);

        self::assertSame(0, $pool->getAvailable(), 'the poisoned connection must not be cached');
        $fresh = $pool->pop();
        self::assertNotSame($dead, $fresh, 'the next pop must mint a fresh connection');
    }
}

/** A sqlite PDO whose rollBack() always fails, modeling a connection that died mid-transaction. */
final class DeadOnRollbackPdo extends \PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
    }

    public function rollBack(): bool
    {
        throw new \PDOException('server has gone away');
    }
}

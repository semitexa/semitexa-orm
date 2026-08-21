<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\ConnectionPool;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;

/**
 * A coroutine that dies while holding a borrowed connection used to leak its
 * pool slot permanently: nothing decremented the Atomic slot counter, so the
 * pool ratcheted toward full-but-empty and every later pop() timed out. The
 * borrow registry arms a per-coroutine defer that gives back whatever is
 * still checked out when the coroutine ends — through push(), so the usual
 * transaction hygiene applies to abandoned mid-transaction connections too.
 */
final class ConnectionPoolSlotReclaimTest extends TestCase
{
    #[Test]
    public function a_connection_abandoned_by_a_dying_coroutine_returns_to_the_pool(): void
    {
        if (!class_exists(\Swoole\Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }

        Coroutine\run(function () {
            $minted = 0;
            $pool = new ConnectionPool(1, static function () use (&$minted): \PDO {
                ++$minted;
                return new \PDO('sqlite::memory:');
            });

            $wg = new WaitGroup();
            $wg->add(1);
            Coroutine::create(function () use ($pool, $wg) {
                try {
                    $pool->pop();
                    // Dies without push() — an uncaught failure path.
                    throw new \RuntimeException('worker coroutine died mid-request');
                } catch (\RuntimeException) {
                    // The throw models the death; the borrow stays unreturned.
                } finally {
                    $wg->done();
                }
            });
            $wg->wait();

            // The abandoned connection must be back: a size-1 pool can only
            // serve this pop() if the dead coroutine's slot was reclaimed.
            self::assertSame(1, $pool->getAvailable(), 'the abandoned connection must be back in the channel');
            $conn = $pool->pop(1.0);
            self::assertInstanceOf(\PDO::class, $conn);
            self::assertSame(1, $minted, 'the reclaimed connection is reused — no replacement was minted');
            $pool->push($conn);

            $pool->close();
        });
    }

    #[Test]
    public function a_connection_abandoned_mid_transaction_comes_back_clean(): void
    {
        if (!class_exists(\Swoole\Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }

        Coroutine\run(function () {
            $pool = new ConnectionPool(1, static fn (): \PDO => new \PDO('sqlite::memory:'));

            $wg = new WaitGroup();
            $wg->add(1);
            Coroutine::create(function () use ($pool, $wg) {
                try {
                    $pdo = $pool->pop();
                    $pdo->exec('CREATE TABLE reclaim_probe (id INTEGER PRIMARY KEY)');
                    $pdo->beginTransaction();
                    $pdo->exec('INSERT INTO reclaim_probe (id) VALUES (1)');
                    // Dies here: open transaction, never pushed.
                } finally {
                    $wg->done();
                }
            });
            $wg->wait();

            $conn = $pool->pop(1.0);
            self::assertFalse($conn->inTransaction(), 'the reclaimed connection must not carry the dead coroutine\'s transaction');
            $count = (int) $conn->query('SELECT COUNT(*) FROM reclaim_probe')->fetchColumn();
            self::assertSame(0, $count, 'the abandoned uncommitted write must be rolled back');
            $pool->push($conn);

            $pool->close();
        });
    }

    #[Test]
    public function the_normal_pop_push_cycle_is_not_disturbed_by_the_reclaim_defer(): void
    {
        if (!class_exists(\Swoole\Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }

        Coroutine\run(function () {
            $pool = new ConnectionPool(2, static fn (): \PDO => new \PDO('sqlite::memory:'));

            $wg = new WaitGroup();
            $wg->add(1);
            Coroutine::create(function () use ($pool, $wg) {
                try {
                    // Several borrow/return cycles in one coroutine: the defer
                    // must find nothing to reclaim when the coroutine ends.
                    for ($i = 0; $i < 3; $i++) {
                        $conn = $pool->pop();
                        $pool->push($conn);
                    }
                } finally {
                    $wg->done();
                }
            });
            $wg->wait();

            self::assertSame(1, $pool->getAvailable(), 'exactly the one returned connection is available — no double-push from the defer');

            $pool->close();
        });
    }
}

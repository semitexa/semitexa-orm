<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\ConnectionPool;
use Semitexa\Orm\OrmManager;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

/**
 * Regression for the transient full-suite fatal:
 *
 *   Uncaught Swoole\Error: must call constructor first
 *   ConnectionPool.php:174 (Channel->isEmpty()) ← close() ← OrmManager
 *   __destruct ← GC during container build
 *
 * A Channel constructed without a coroutine context defers its C-level init;
 * ANY method on it then raises a fatal that bypasses try/catch — and inside a
 * destructor no frame can catch it. Two pins:
 *
 *  - close() outside a coroutine must not invoke a single Channel method
 *    (spy channel records calls);
 *  - OrmManager's destructor must never drain the pool at all — destructors
 *    run wherever GC fires, so reference-dropping is their only safe move.
 *
 * The companion in-coroutine test keeps the guard honest: a deliberate
 * close() inside a coroutine still drains.
 */
final class ConnectionPoolCoroutinelessCloseTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }
        if (Coroutine::getCid() >= 0) {
            self::markTestSkipped('This regression is specifically about the coroutineless context.');
        }
    }

    #[Test]
    public function close_outside_a_coroutine_touches_no_channel_method(): void
    {
        $pool = new ConnectionPool(2, static fn (): \PDO => new \PDO('sqlite::memory:'));
        $spy = $this->spyChannel();
        $this->plantChannel($pool, $spy);

        $pool->close();

        self::assertSame([], $spy->calls, 'Channel methods are illegal without a coroutine — the drain must be skipped.');
        self::assertSame(0, $pool->getAvailable(), 'The pool reference must still be dropped.');
    }

    #[Test]
    public function orm_manager_destructor_never_drains_the_pool(): void
    {
        $orm = new OrmManager();
        $pool = new ConnectionPool(2, static fn (): \PDO => new \PDO('sqlite::memory:'));
        $spy = $this->spyChannel();
        $this->plantChannel($pool, $spy);

        $slot = new \ReflectionProperty(OrmManager::class, 'pool');
        $slot->setValue($orm, $pool);

        unset($orm); // GC → __destruct — the exact frame of the live fatal.

        self::assertSame([], $spy->calls, 'A destructor may only drop references; Channel ops fatal in foreign contexts.');
    }

    #[Test]
    public function deliberate_close_inside_a_coroutine_still_drains(): void
    {
        $calls = null;
        Coroutine\run(function () use (&$calls): void {
            $pool = new ConnectionPool(1, static fn (): \PDO => new \PDO('sqlite::memory:'));
            $spy = $this->spyChannel();
            $this->plantChannel($pool, $spy);

            $pool->close();
            $calls = $spy->calls;
        });

        self::assertNotSame([], $calls, 'Inside a coroutine the guard must not block the real drain.');
        self::assertContains('close', $calls);
    }

    /** @return Channel&object{calls: list<string>} */
    private function spyChannel(): Channel
    {
        return new class (1) extends Channel {
            /** @var list<string> */
            public array $calls = [];

            public function isEmpty(): bool
            {
                $this->calls[] = 'isEmpty';
                return true;
            }

            public function pop(float $timeout = -1): mixed
            {
                $this->calls[] = 'pop';
                return false;
            }

            public function close(): bool
            {
                $this->calls[] = 'close';
                return true;
            }
        };
    }

    private function plantChannel(ConnectionPool $pool, Channel $channel): void
    {
        $slot = new \ReflectionProperty(ConnectionPool::class, 'pool');
        $slot->setValue($pool, $channel);
    }
}

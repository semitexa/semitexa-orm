<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Connection;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionObject;
use Semitexa\Orm\Adapter\SingleConnectionPool;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;
use Swoole\Runtime;

/**
 * The self-heal swap used to be gated only on TransactionManager::isActive(),
 * which is COROUTINE-LOCAL: it answers for the coroutine asking for the swap,
 * not for the worker. So coroutine A could be mid-query on the old
 * SingleConnectionPool while coroutine B reached a getter, saw no transaction
 * of its own, and closed A's connection out from under it. The pool itself is
 * the only worker-wide witness of an outstanding borrow.
 */
final class PoolSwapBorrowGuardTest extends TestCase
{
    private int $savedHookFlags = 0;

    protected function setUp(): void
    {
        if (!class_exists(Runtime::class) || !class_exists(Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }

        $this->savedHookFlags = Runtime::getHookFlags();
    }

    protected function tearDown(): void
    {
        if (class_exists(Runtime::class)) {
            Runtime::enableCoroutine($this->savedHookFlags);
        }
    }

    #[Test]
    public function a_pool_another_coroutine_is_holding_is_not_swapped(): void
    {
        Runtime::enableCoroutine(0); // pre-fork: the stale single pool is selected
        $manager = new OrmManager(config: new ConnectionConfig(driver: 'mysql'));

        $stale = $manager->getPool();
        self::assertInstanceOf(SingleConnectionPool::class, $stale);

        // Hand the pool a connection and mark it owned by a live coroutine,
        // without touching a database: this is the state "coroutine A is
        // mid-query" as the pool itself sees it.
        Coroutine\run(function () use ($manager, $stale) {
            $wg = new WaitGroup();
            $wg->add(1);

            $holder = Coroutine::create(function () use ($wg) {
                // Stay alive while the other coroutine asks for a swap.
                Coroutine::sleep(0.2);
                $wg->done();
            });

            $this->setPrivate($stale, 'connection', new \PDO('sqlite::memory:'));
            $this->setPrivate($stale, 'ownerCid', $holder);

            Runtime::enableCoroutine(SWOOLE_HOOK_ALL); // hooks now live

            self::assertTrue($stale->hasOutstandingBorrow(), 'Precondition: the pool must report the live borrow.');
            self::assertSame(
                $stale,
                $manager->getPool(),
                'the pool must not be closed while another coroutine still holds its connection',
            );

            $wg->wait();
        });

        // Once the holder is gone the pool is quiescent and the swap proceeds
        // for whoever asks next.
        $this->setPrivate($stale, 'ownerCid', -1);
        Coroutine\run(function () use ($manager, $stale) {
            self::assertNotSame($stale, $manager->getPool(), 'a quiescent stale pool must still be upgraded');
        });
    }

    private function setPrivate(object $target, string $property, mixed $value): void
    {
        $prop = (new ReflectionObject($target))->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue($target, $value);
    }
}

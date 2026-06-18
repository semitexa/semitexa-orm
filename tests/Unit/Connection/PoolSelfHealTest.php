<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Connection;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionObject;
use Semitexa\Orm\Adapter\ConnectionPool;
use Semitexa\Orm\Adapter\SingleConnectionPool;
use Semitexa\Orm\Application\Service\Transaction\TransactionManager;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Swoole\Coroutine;
use Swoole\Runtime;

/**
 * Pins OrmManager's self-healing pool upgrade (ensureCoroutineSafePool).
 *
 * CreatePoolGateTest proves the FIRST selection is correct when hooks are already
 * up. This proves the harder case: the first getPool() ran with hooks OFF (e.g.
 * master-side warmup before fork) and cached a SingleConnectionPool — which is
 * otherwise frozen for the worker's life. Once the runtime is live, the next
 * getPool()/getAdapter() must swap in the coroutine-safe ConnectionPool and drop
 * the stale adapter, rather than degrade the worker forever.
 *
 * The factory closure is never invoked (no pop()), so no live database is needed.
 */
final class PoolSelfHealTest extends TestCase
{
    private int $savedHookFlags = 0;

    protected function setUp(): void
    {
        if (!class_exists(Runtime::class) || !class_exists(Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }

        $this->savedHookFlags = Runtime::getHookFlags();
        self::assertSame(-1, Coroutine::getCid(), 'Precondition: test body must run outside a coroutine.');
    }

    protected function tearDown(): void
    {
        if (class_exists(Runtime::class)) {
            Runtime::enableCoroutine($this->savedHookFlags);
        }
    }

    #[Test]
    public function it_upgrades_a_stale_single_pool_once_hooks_come_up(): void
    {
        Runtime::enableCoroutine(0); // master-side / pre-fork: hooks OFF
        $manager = $this->mysqlManager();

        $first = $manager->getPool();
        self::assertInstanceOf(SingleConnectionPool::class, $first, 'With hooks OFF the single pool is selected.');

        Runtime::enableCoroutine(SWOOLE_HOOK_ALL); // server worker now live

        $second = $manager->getPool();
        self::assertInstanceOf(ConnectionPool::class, $second, 'Once hooks are live the pool must self-heal to ConnectionPool.');
        self::assertNotSame($first, $second);
    }

    #[Test]
    public function it_rebuilds_the_adapter_after_a_pool_upgrade(): void
    {
        Runtime::enableCoroutine(0);
        $manager = $this->mysqlManager();

        $adapterBefore = $manager->getAdapter(); // built over the SingleConnectionPool

        Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

        $adapterAfter = $manager->getAdapter();
        self::assertNotSame(
            $adapterBefore,
            $adapterAfter,
            'The adapter captured the stale pool by value, so it must be rebuilt over the upgraded pool.',
        );
    }

    #[Test]
    public function it_does_not_churn_the_pool_in_true_cli(): void
    {
        Runtime::enableCoroutine(0); // stays OFF — true CLI
        $manager = $this->mysqlManager();

        $first  = $manager->getPool();
        $second = $manager->getPool();

        self::assertSame($first, $second, 'With hooks OFF throughout, the single pool must be stable across calls.');
    }

    #[Test]
    public function it_defers_the_upgrade_while_a_transaction_is_active(): void
    {
        Runtime::enableCoroutine(0);
        $manager = $this->mysqlManager();

        $single = $manager->getPool();
        self::assertInstanceOf(SingleConnectionPool::class, $single);

        // Simulate an open transaction on the cached manager.
        $tm = new TransactionManager($single, $manager->getAdapter());
        $this->setPrivate($tm, 'depth', 1);
        $this->setPrivate($manager, 'transactionManager', $tm);

        Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

        self::assertInstanceOf(
            SingleConnectionPool::class,
            $manager->getPool(),
            'The pool must not be yanked out from under an active transaction.',
        );
    }

    private function mysqlManager(): OrmManager
    {
        // Force the mysql driver so getPool()/getAdapter() never take the sqlite branch,
        // independent of the container env. No connection is opened (the factory is lazy).
        return new OrmManager(config: new ConnectionConfig(driver: 'mysql'));
    }

    private function setPrivate(object $target, string $property, mixed $value): void
    {
        $ref = new ReflectionObject($target);
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue($target, $value);
    }
}

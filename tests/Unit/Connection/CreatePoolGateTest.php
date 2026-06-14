<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Connection;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Semitexa\Orm\Adapter\ConnectionPool;
use Semitexa\Orm\Adapter\SingleConnectionPool;
use Semitexa\Orm\OrmManager;
use Swoole\Coroutine;
use Swoole\Runtime;

/**
 * Pins the OrmManager::createPool() pool-selection gate.
 *
 * The concurrency crash existed because the gate keyed off getCid() >= 0 — "am I in a
 * coroutine right now". When the first getPool() happened outside a coroutine (worker boot),
 * the worker froze onto a single shared PDO and crashed at >=3 concurrent reads. The fix keys
 * off Runtime::getHookFlags() !== 0 — the causally-exact "Swoole server present / PDO sockets
 * coroutine-hooked" signal, which is stable per worker (hooks are enabled in the master before
 * fork). These assertions are deterministic — no concurrency needed to reproduce the choice.
 */
final class CreatePoolGateTest extends TestCase
{
    private int $savedHookFlags = 0;

    protected function setUp(): void
    {
        if (!class_exists(Runtime::class) || !class_exists(Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }

        // PHPUnit runs as a plain CLI process (no Swoole server), so hooks default to OFF and
        // we are not inside a coroutine. Snapshot the flags so we can restore them afterwards.
        $this->savedHookFlags = Runtime::getHookFlags();
        self::assertSame(-1, Coroutine::getCid(), 'Precondition: test body must run outside a coroutine.');
    }

    protected function tearDown(): void
    {
        if (class_exists(Runtime::class)) {
            Runtime::enableCoroutine($this->savedHookFlags);
        }
    }

    /**
     * The exact condition that previously froze the single pool: runtime hooks ON (server worker)
     * but the call is made OUTSIDE a coroutine (getCid() === -1). It must now pick ConnectionPool.
     */
    #[Test]
    public function it_returns_connection_pool_when_hooks_on_outside_coroutine(): void
    {
        Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

        self::assertNotSame(0, Runtime::getHookFlags(), 'Hooks should be enabled for this case.');
        self::assertSame(-1, Coroutine::getCid(), 'This case must run outside a coroutine.');

        $pool = $this->invokeCreatePool();

        self::assertInstanceOf(
            ConnectionPool::class,
            $pool,
            'With server hooks ON, createPool() must return the coroutine-safe ConnectionPool even '
            . 'outside a coroutine — this removes the build-time timing dependency that caused the crash.',
        );
    }

    /**
     * True CLI: no Swoole server, hooks OFF, no coroutine. The single shared connection is correct
     * there (no coroutine hooking, one blocking connection). Migrations/console/tests stay on it.
     */
    #[Test]
    public function it_returns_single_connection_pool_when_hooks_off_cli(): void
    {
        Runtime::enableCoroutine(0);

        self::assertSame(0, Runtime::getHookFlags(), 'Hooks should be disabled for the CLI case.');
        self::assertSame(-1, Coroutine::getCid(), 'CLI case must run outside a coroutine.');

        $pool = $this->invokeCreatePool();

        self::assertInstanceOf(
            SingleConnectionPool::class,
            $pool,
            'With hooks OFF (true CLI), createPool() must keep returning the SingleConnectionPool.',
        );
    }

    private function invokeCreatePool(): object
    {
        // createPool() builds the pool object and the PDO factory closure but never invokes the
        // factory, so no live database is required to assert the selected pool type.
        $manager = new OrmManager();
        $method = new ReflectionMethod(OrmManager::class, 'createPool');
        $method->setAccessible(true);

        return $method->invoke($manager);
    }
}

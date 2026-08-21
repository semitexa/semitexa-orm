<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\ConnectionPool;
use Swoole\Coroutine;

/**
 * The `SELECT 1` liveness ping used to run on EVERY checkout, doubling the
 * round-trips per query. It is now gated on idleness: a connection returned
 * milliseconds ago is served as-is; one idle past the threshold (or with no
 * idle stamp at all) is pinged and replaced if dead.
 */
final class ConnectionPoolIdlePingTest extends TestCase
{
    #[Test]
    public function a_hot_connection_is_served_without_a_ping(): void
    {
        if (!class_exists(\Swoole\Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }

        Coroutine\run(function () {
            $pool = new ConnectionPool(1, static fn (): \PDO => new PingCountingPdo());

            $conn = $pool->pop();
            self::assertInstanceOf(PingCountingPdo::class, $conn);
            $pool->push($conn);

            // Immediately re-popped: fresh idle stamp, no ping.
            $again = $pool->pop();
            self::assertSame($conn, $again);
            self::assertSame(0, $again->pings, 'a connection returned milliseconds ago must not be pinged');
            $pool->push($again);

            $pool->close();
        });
    }

    #[Test]
    public function a_connection_idle_past_the_threshold_is_pinged(): void
    {
        if (!class_exists(\Swoole\Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }

        Coroutine\run(function () {
            $pool = new ConnectionPool(1, static fn (): \PDO => new PingCountingPdo());

            $conn = $pool->pop();
            $pool->push($conn);

            // Backdate the idle stamp instead of sleeping past the threshold.
            $prop = new \ReflectionProperty(ConnectionPool::class, 'idleSince');
            /** @var \WeakMap<\PDO, float> $idleSince */
            $idleSince = $prop->getValue($pool);
            $idleSince[$conn] = microtime(true) - 60.0;

            $again = $pool->pop();
            self::assertSame($conn, $again);
            self::assertSame(1, $again->pings, 'a connection idle past the threshold must be health-checked');
            $pool->push($again);

            $pool->close();
        });
    }

    #[Test]
    public function fill_warms_only_up_to_the_requested_target(): void
    {
        if (!class_exists(\Swoole\Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }

        Coroutine\run(function () {
            $minted = 0;
            $pool = new ConnectionPool(3, static function () use (&$minted): \PDO {
                ++$minted;
                return new \PDO('sqlite::memory:');
            });

            $pool->fill(2);
            self::assertSame(2, $minted, 'a partial warm must stop at the target');
            self::assertSame(2, $pool->getAvailable());

            $pool->fill(99);
            self::assertSame(3, $minted, 'the target is clamped to the pool size');
            self::assertSame(3, $pool->getAvailable());

            $pool->close();
        });
    }
}

/** A sqlite-backed PDO that counts liveness pings. */
final class PingCountingPdo extends \PDO
{
    public int $pings = 0;

    public function __construct()
    {
        parent::__construct('sqlite::memory:');
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        if ($query === 'SELECT 1') {
            $this->pings++;
        }

        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}

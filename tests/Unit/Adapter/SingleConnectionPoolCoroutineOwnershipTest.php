<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\SingleConnectionPool;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;

/**
 * The pool's one hard rule: a PDO socket may only ever be in use by the
 * coroutine that owns it.
 *
 * Break it and Swoole does not throw something catchable — it raises
 * `Socket#N has already been bound to another coroutine#M` and takes the worker
 * with it. That fatal was seen in production at `ensureAlive()`'s `SELECT 1`,
 * under load only, passing 3/3 in isolation: the signature of a race, not a
 * broken test.
 *
 * These tests model socket ownership inside the fake PDO — `query()` records the
 * coroutine holding it and flags any other coroutine that queries while it is
 * held. That is the closest a unit test can get: the real fatal is raised by
 * Swoole's hooked socket layer, which a stub PDO never reaches. So this asserts
 * the *invariant that prevents* the fatal rather than the fatal itself, and the
 * distinction is worth knowing when reading a failure here.
 */
final class SingleConnectionPoolCoroutineOwnershipTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }

        OwnershipTrackingPdo::reset();
    }

    #[Test]
    public function a_connection_returned_by_someone_other_than_its_owner_is_not_handed_on(): void
    {
        // The regression. Ownership used to be released by ANY push(), so a
        // return from a deferred continuation, an async teardown, or a `finally`
        // running in its own coroutine reset ownerCid to -1 while the real owner
        // was still mid-query. The next pop() then saw an unowned connection,
        // reused it, and ran SELECT 1 on a socket that was still bound.
        $pool = new SingleConnectionPool(static fn (): \PDO => new OwnershipTrackingPdo());
        $shared = null;

        Coroutine\run(function () use ($pool, &$shared): void {
            $wg = new WaitGroup();

            // Owner: takes the connection and keeps using it across a yield.
            $wg->add();
            Coroutine::create(function () use ($pool, &$shared, $wg): void {
                $shared = $pool->pop();
                Coroutine::sleep(0.002);
                $shared->query('SELECT * FROM orders');
                $wg->done();
            });

            Coroutine::sleep(0.001);

            // A different coroutine hands the owner's connection back.
            $wg->add();
            Coroutine::create(function () use ($pool, &$shared, $wg): void {
                if ($shared !== null) {
                    $pool->push($shared);
                }
                $wg->done();
            });

            Coroutine::sleep(0.001);

            // A third coroutine now asks for a connection.
            $wg->add();
            Coroutine::create(function () use ($pool, $wg): void {
                $pool->pop();
                $wg->done();
            });

            $wg->wait();
        });

        self::assertSame(
            [],
            OwnershipTrackingPdo::$violations,
            'a socket was queried by a coroutine that did not own it — in production this is the '
            . 'uncatchable Swoole fatal "Socket#N has already been bound to another coroutine#M"',
        );
        self::assertSame(
            2,
            OwnershipTrackingPdo::$created,
            'the third coroutine must get its OWN connection rather than the one still in use',
        );
    }

    #[Test]
    public function concurrent_coroutines_never_share_one_connection(): void
    {
        $pool = new SingleConnectionPool(static function (): \PDO {
            Coroutine::sleep(0.001); // connecting is I/O; it yields

            return new OwnershipTrackingPdo();
        });

        Coroutine\run(function () use ($pool): void {
            $wg = new WaitGroup();
            for ($i = 0; $i < 6; $i++) {
                $wg->add();
                Coroutine::create(function () use ($pool, $wg): void {
                    $connection = $pool->pop();
                    $connection->query('SELECT 1');
                    Coroutine::sleep(0.002);
                    $pool->push($connection);
                    $wg->done();
                });
            }
            $wg->wait();
        });

        self::assertSame([], OwnershipTrackingPdo::$violations);
    }

    #[Test]
    public function a_coroutine_that_dies_without_returning_its_connection_does_not_poison_the_pool(): void
    {
        // A handler that throws never reaches push(). The pool must still serve
        // the next caller — and must not serve it the abandoned socket while it
        // could still be bound.
        $pool = new SingleConnectionPool(static fn (): \PDO => new OwnershipTrackingPdo());

        Coroutine\run(function () use ($pool): void {
            Coroutine::create(function () use ($pool): void {
                try {
                    $pool->pop();
                    throw new \RuntimeException('handler blew up');
                } catch (\Throwable) {
                    // died without push()
                }
            });

            Coroutine::sleep(0.005);

            $wg = new WaitGroup();
            for ($i = 0; $i < 3; $i++) {
                $wg->add();
                Coroutine::create(function () use ($pool, $wg): void {
                    $connection = $pool->pop();
                    $connection->query('SELECT 1');
                    $pool->push($connection);
                    $wg->done();
                });
            }
            $wg->wait();
        });

        self::assertSame([], OwnershipTrackingPdo::$violations);
    }

    #[Test]
    public function the_owner_can_still_return_its_own_connection(): void
    {
        // The guard must not overreach: refusing a FOREIGN push is the point,
        // refusing the owner's own push would stop the pool ever reusing
        // anything and turn a single-connection pool into connect-per-query.
        $pool = new SingleConnectionPool(static fn (): \PDO => new OwnershipTrackingPdo());

        Coroutine\run(function () use ($pool): void {
            $wg = new WaitGroup();
            $wg->add();
            Coroutine::create(function () use ($pool, $wg): void {
                $first = $pool->pop();
                $pool->push($first);

                $second = $pool->pop();
                $pool->push($second);

                $wg->done();
            });
            $wg->wait();
        });

        self::assertSame(
            1,
            OwnershipTrackingPdo::$created,
            'sequential use by one coroutine must reuse the cached connection',
        );
    }
}

/**
 * A PDO stub that models the one thing a stub normally cannot: which coroutine
 * currently holds the socket.
 */
final class OwnershipTrackingPdo extends \PDO
{
    public static int $created = 0;

    /** @var list<string> */
    public static array $violations = [];

    public int $id;

    /** Coroutine currently inside a query on this socket; -1 when free. */
    private int $boundTo = -1;

    public function __construct()
    {
        $this->id = ++self::$created;
    }

    public static function reset(): void
    {
        self::$created = 0;
        self::$violations = [];
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        $cid = Coroutine::getCid();

        if ($this->boundTo >= 0 && $this->boundTo !== $cid) {
            self::$violations[] = sprintf(
                'pdo#%d was bound to coroutine#%d when coroutine#%d queried it',
                $this->id,
                $this->boundTo,
                $cid,
            );
        }

        $this->boundTo = $cid;
        Coroutine::sleep(0.002); // hooked socket I/O suspends here
        $this->boundTo = -1;

        return new class extends \PDOStatement {};
    }
}

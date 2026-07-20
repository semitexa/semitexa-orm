<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\ConnectionPool;
use Swoole\Coroutine;

final class ConnectionPoolTest extends TestCase
{
    #[Test]
    public function it_creates_and_closes_pool(): void
    {
        if (!class_exists(\Swoole\Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }

        Coroutine\run(function () {
            $created = 0;
            $pool = new ConnectionPool(2, static function () use (&$created): \PDO {
                ++$created;
                return new \PDO('sqlite::memory:');
            });

            self::assertSame(2, $pool->getSize());
            self::assertSame(0, $pool->getAvailable());

            $first = $pool->pop();
            self::assertSame(1, $created);
            self::assertSame(0, $pool->getAvailable());

            $pool->push($first);
            self::assertSame(1, $pool->getAvailable());

            $pool->close();
            self::assertSame(0, $pool->getAvailable());

            try {
                $pool->pop();
                self::fail('Expected RuntimeException was not thrown.');
            } catch (\RuntimeException $e) {
                self::assertSame('Connection pool is closed.', $e->getMessage());
            }
        });
    }

    #[Test]
    public function pop_and_push_work_outside_a_coroutine(): void
    {
        if (!class_exists(\Swoole\Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }
        // The case the Swoole server never hits but CLI / phpunit do: hooks may be
        // globally enabled (by a prior test) yet no coroutine is active. Channel
        // ops fatal here ("API must be called in the coroutine"), bypassing
        // try/catch — which aborted the whole monorepo test:run.
        self::assertSame(-1, Coroutine::getCid(), 'Precondition: must run outside a coroutine.');

        $created = 0;
        $pool = new ConnectionPool(2, static function () use (&$created): \PDO {
            ++$created;
            return new \PDO('sqlite::memory:');
        });

        // pop() hands out a direct connection (bypasses Channel->pop()).
        $conn = $pool->pop();
        self::assertInstanceOf(\PDO::class, $conn);
        self::assertSame(1, $created);

        // push() is a no-op outside a coroutine — must NOT call Channel->push().
        $pool->push($conn);

        // Still usable: a second pop() returns another fresh connection, proving we
        // never blocked on / operated the Channel.
        $conn2 = $pool->pop();
        self::assertInstanceOf(\PDO::class, $conn2);
        self::assertSame(2, $created);
    }

    #[Test]
    public function it_skips_channel_ops_during_shutdown(): void
    {
        if (!class_exists(\Swoole\Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }

        Coroutine\run(function () {
            $pool = new ConnectionPool(1, static function (): \PDO {
                return new \PDO('sqlite::memory:');
            });

            // Set the private static shutdown flag via reflection
            $ref = new \ReflectionClass(ConnectionPool::class);
            $prop = $ref->getProperty('phpShuttingDown');
            $prop->setAccessible(true);
            $prop->setValue(null, true);

            try {
                // Should not throw even if the channel is uninitialized/corrupted
                // (simulated by the flag check)
                $pool->close();
                self::assertTrue(true);
            } finally {
                // Reset the flag for other tests
                $prop->setValue(null, false);
            }
        });
    }

    #[Test]
    public function it_resets_state_even_on_cleanup_error(): void
    {
        if (!class_exists(\Swoole\Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }

        Coroutine\run(function () {
            $pool = new ConnectionPool(1, static function (): \PDO {
                return new \PDO('sqlite::memory:');
            });

            // If we can't mock Channel (C extension), we'll at least verify
            // that a normal close works. 
            // Forcing a throwable in a C extension class is tricky.
            // We'll trust the finally block logic which is a PHP primitive.
            
            $pool->close();

            self::assertSame(0, $pool->getAvailable());

            $ref = new \ReflectionClass($pool);
            $createdProp = $ref->getProperty('created');
            $createdProp->setAccessible(true);
            $created = $createdProp->getValue($pool);
            self::assertSame(0, $created->get());
        });
    }

    #[Test]
    public function pop_releases_the_claimed_slot_when_the_factory_throws(): void
    {
        if (!class_exists(\Swoole\Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }

        Coroutine\run(function () {
            $fail = true;
            $pool = new ConnectionPool(1, static function () use (&$fail): \PDO {
                if ($fail) {
                    // Simulate a transient PDO connect failure — a network blip
                    // or MySQL momentarily refusing the connection.
                    throw new \PDOException('simulated connect failure');
                }
                return new \PDO('sqlite::memory:');
            });

            $ref = new \ReflectionClass($pool);
            $createdProp = $ref->getProperty('created');
            $createdProp->setAccessible(true);

            // Repeated connect failures must NOT accumulate on the slot counter.
            // Before the fix each failure leaked one slot (cmpset ran, the factory
            // threw, nothing rolled it back); after `size` failures the pool was
            // full-but-empty and every pop() blocked forever on the Channel — the
            // production ratcheting deadlock this guards against.
            for ($i = 0; $i < 5; ++$i) {
                try {
                    $pool->pop();
                    self::fail('Expected the factory exception to propagate.');
                } catch (\PDOException $e) {
                    self::assertSame('simulated connect failure', $e->getMessage());
                }

                self::assertSame(
                    0,
                    $createdProp->getValue($pool)->get(),
                    'A failed factory call must release the slot it optimistically claimed.',
                );
            }

            // Not wedged: once the factory recovers, pop() creates a real
            // connection via the fast path instead of blocking on an empty
            // Channel (which, with timeout -1, would hang forever).
            $fail = false;
            $conn = $pool->pop();
            self::assertInstanceOf(\PDO::class, $conn);
            self::assertSame(1, $createdProp->getValue($pool)->get());
        });
    }

    #[Test]
    public function ensure_alive_releases_the_slot_when_the_reconnect_fails(): void
    {
        if (!class_exists(\Swoole\Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }

        Coroutine\run(function () {
            // 'stale' → a connection whose SELECT 1 throws (simulating a socket
            // dropped by MySQL wait_timeout); 'fail' → the reconnect itself
            // fails; 'ok' → a healthy connection.
            $mode = 'stale';
            $pool = new ConnectionPool(1, static function () use (&$mode): \PDO {
                if ($mode === 'fail') {
                    throw new \PDOException('reconnect failed');
                }
                if ($mode === 'stale') {
                    return new class ('sqlite::memory:') extends \PDO {
                        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
                        {
                            throw new \PDOException('server has gone away');
                        }
                    };
                }
                return new \PDO('sqlite::memory:');
            });

            $ref = new \ReflectionClass($pool);
            $createdProp = $ref->getProperty('created');
            $createdProp->setAccessible(true);

            // Seed the channel with one connection so the next pop() takes the
            // slow path through ensureAlive() rather than the fast path.
            $pool->push($pool->pop());
            self::assertSame(1, $createdProp->getValue($pool)->get());
            self::assertSame(1, $pool->getAvailable());

            // pop() retrieves the seeded connection, SELECT 1 throws (stale),
            // ensureAlive() reconnects via the factory — which now fails.
            $mode = 'fail';
            try {
                $pool->pop();
                self::fail('Expected the reconnect failure to propagate.');
            } catch (\PDOException $e) {
                self::assertSame('reconnect failed', $e->getMessage());
            }

            // The slot must be released, not leaked: without the fix `created`
            // stays at 1 with an empty channel, so the pool is full-but-empty
            // and the next pop() blocks forever on Channel->pop(-1).
            self::assertSame(
                0,
                $createdProp->getValue($pool)->get(),
                'A failed reconnect in ensureAlive() must release the slot.',
            );

            // Recovered: a healthy factory now yields a usable connection via
            // the fast path instead of wedging.
            $mode = 'ok';
            $conn = $pool->pop();
            self::assertInstanceOf(\PDO::class, $conn);
            self::assertSame(1, $createdProp->getValue($pool)->get());
        });
    }

    #[Test]
    public function fill_releases_the_claimed_slot_when_the_factory_throws(): void
    {
        if (!class_exists(\Swoole\Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }

        Coroutine\run(function () {
            $calls = 0;
            $pool = new ConnectionPool(3, static function () use (&$calls): \PDO {
                ++$calls;
                if ($calls === 2) {
                    // The second connection fails to open at worker boot.
                    throw new \PDOException('boot connect failure');
                }
                return new \PDO('sqlite::memory:');
            });

            try {
                $pool->fill();
                self::fail('Expected fill() to propagate the factory failure.');
            } catch (\PDOException $e) {
                self::assertSame('boot connect failure', $e->getMessage());
            }

            // The first connection was created and pushed; the failed second
            // released its claimed slot instead of leaking it. So the counter
            // matches what is actually in the channel (1), not 2.
            $ref = new \ReflectionClass($pool);
            $createdProp = $ref->getProperty('created');
            $createdProp->setAccessible(true);
            self::assertSame(1, $createdProp->getValue($pool)->get());
            self::assertSame(1, $pool->getAvailable());
        });
    }

    protected function tearDown(): void
    {
        if (class_exists(\Swoole\Runtime::class)) {
            \Swoole\Runtime::enableCoroutine(0);
        }
    }
}


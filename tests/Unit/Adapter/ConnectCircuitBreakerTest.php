<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\ConnectCircuitBreaker;
use Semitexa\Orm\Adapter\ConnectCircuitOpenException;
use Semitexa\Orm\Adapter\ConnectionPool;
use Semitexa\Orm\Adapter\DriverErrorClassifier;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\Exception\ConnectionLostException;
use Semitexa\Orm\OrmManager;

final class ConnectCircuitBreakerTest extends TestCase
{
    private float $now = 100.0;

    private int $attempts = 0;

    private bool $dbUp = false;

    private function breaker(float $cooldown = 2.0, float $lease = 10.0): ConnectCircuitBreaker
    {
        return new ConnectCircuitBreaker($cooldown, $lease, fn (): float => $this->now);
    }

    private function factory(): \Closure
    {
        return function (): \PDO {
            $this->attempts++;
            if (! $this->dbUp) {
                $e = new \PDOException('SQLSTATE[HY000] [2002] Connection refused');
                $e->errorInfo = ['HY000', 2002, 'Connection refused'];
                throw $e;
            }

            return new \PDO('sqlite::memory:');
        };
    }

    #[Test]
    public function it_fails_fast_with_the_original_failure_during_the_cooldown(): void
    {
        $connect = $this->breaker()->wrap($this->factory());

        try {
            $connect();
            self::fail('first connect must fail');
        } catch (\PDOException $first) {
            self::assertNotInstanceOf(ConnectCircuitOpenException::class, $first);
        }

        $this->now += 1.9;
        try {
            $connect();
            self::fail('connect inside the cooldown must fail fast');
        } catch (ConnectCircuitOpenException $e) {
            self::assertSame($first->getMessage(), $e->getMessage());
            self::assertSame($first->getCode(), $e->getCode());
            self::assertSame($first->errorInfo, $e->errorInfo);
            self::assertSame($first, $e->getPrevious());
            self::assertInstanceOf(ConnectionLostException::class, DriverErrorClassifier::classify($e));
        }

        self::assertSame(1, $this->attempts, 'no connect attempt may be made while the breaker is open');
    }

    #[Test]
    public function it_lets_one_probe_through_after_the_cooldown_and_closes_on_success(): void
    {
        $breaker = $this->breaker();
        $connect = $breaker->wrap($this->factory());

        try {
            $connect();
        } catch (\PDOException) {
        }

        $this->now += 2.0;
        try {
            $connect();
            self::fail('probe against a still-down database must fail');
        } catch (\PDOException $e) {
            self::assertNotInstanceOf(ConnectCircuitOpenException::class, $e, 'the probe is a real attempt');
        }
        self::assertSame(2, $this->attempts);

        // Failed probe re-opens the window.
        $this->now += 1.0;
        $this->expectFailFast($connect);
        self::assertSame(2, $this->attempts);

        $this->dbUp = true;
        $this->now += 1.0;
        $connect();
        self::assertFalse($breaker->isOpen(), 'a successful connect must close the breaker');

        $connect();
        self::assertSame(4, $this->attempts);
    }

    #[Test]
    public function it_fails_fast_while_a_probe_is_in_flight_and_releases_a_stale_probe(): void
    {
        $breaker = $this->breaker(2.0, 5.0);
        $connect = $breaker->wrap($this->factory());

        try {
            $connect();
        } catch (\PDOException) {
        }
        $this->now += 3.0;

        // A probe whose factory re-enters the breaker models a second coroutine
        // arriving while the probe's connect is suspended.
        $nested = null;
        $probeFactory = function () use ($breaker, &$nested): \PDO {
            try {
                $breaker->connect($this->factory());
            } catch (\PDOException $e) {
                $nested = $e;
            }
            $this->dbUp = true;

            return ($this->factory())();
        };
        $breaker->connect($probeFactory);

        self::assertInstanceOf(ConnectCircuitOpenException::class, $nested);
        self::assertFalse($breaker->isOpen());
    }

    #[Test]
    public function a_killed_probe_does_not_wedge_the_breaker_open(): void
    {
        $breaker = $this->breaker(2.0, 5.0);
        $connect = $breaker->wrap($this->factory());
        try {
            $connect();
        } catch (\PDOException) {
        }

        // Simulate a probe that never returns by claiming the probe via reflection.
        $this->now += 2.0;
        (new \ReflectionProperty($breaker, 'probeStartedAt'))->setValue($breaker, $this->now);
        $this->expectFailFast($connect);

        $this->now += 5.0;
        $this->dbUp = true;
        $connect();
        self::assertFalse($breaker->isOpen());
    }

    #[Test]
    public function zero_cooldown_disables_the_breaker(): void
    {
        $factory = $this->factory();
        self::assertSame($factory, $this->breaker(0.0)->wrap($factory));
    }

    #[Test]
    public function coroutine_pool_fails_fast_without_touching_open_connections(): void
    {
        if (! class_exists(\Swoole\Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }

        $this->dbUp = true;
        $pool = new ConnectionPool(3, $this->breaker()->wrap($this->factory()));
        $errors = [];
        $reused = false;

        \Swoole\Coroutine\run(function () use ($pool, &$errors, &$reused): void {
            $open = $pool->pop();
            $this->dbUp = false;

            foreach ([1, 2, 3] as $_) {
                try {
                    $pool->pop();
                } catch (\PDOException $e) {
                    $errors[] = $e::class;
                }
            }

            // The already-open connection is untouched and still usable.
            $pool->push($open);
            $reused = $pool->pop() === $open;
            $pool->push($open);
        });

        self::assertSame(
            [\PDOException::class, ConnectCircuitOpenException::class, ConnectCircuitOpenException::class],
            $errors,
        );
        self::assertSame(2, $this->attempts);
        self::assertTrue($reused);
        self::assertSame(1, $pool->getStats()['created'], 'failed connects must not leak pool slots');
    }

    #[Test]
    public function orm_manager_pool_fails_fast_against_an_unreachable_database(): void
    {
        $manager = new OrmManager(config: new ConnectionConfig(
            host: '127.0.0.1',
            port: '1',
            connectTimeout: 1.0,
            connectFailureCooldown: 30.0,
        ));

        try {
            $manager->getPool()->pop();
            self::fail('connect to a closed port must fail');
        } catch (\PDOException $first) {
            self::assertNotInstanceOf(ConnectCircuitOpenException::class, $first);
        }

        $started = hrtime(true);
        try {
            $manager->getPool()->pop();
            self::fail('second connect must fail fast');
        } catch (ConnectCircuitOpenException $e) {
            self::assertSame($first->getMessage(), $e->getMessage());
        }
        self::assertLessThan(0.05, (hrtime(true) - $started) / 1e9);
    }

    private function expectFailFast(\Closure $connect): void
    {
        try {
            $connect();
            self::fail('expected a fail-fast connect');
        } catch (ConnectCircuitOpenException) {
            self::addToAssertionCount(1);
        }
    }
}

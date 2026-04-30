<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\SingleConnectionPool;

final class SingleConnectionPoolTest extends TestCase
{
    #[Test]
    public function it_reuses_alive_connection(): void
    {
        $created = 0;
        $pool = new SingleConnectionPool(static function () use (&$created): \PDO {
            ++$created;

            return new HealthyPdo();
        });

        $first = $pool->pop();
        $pool->push($first);

        $second = $pool->pop();

        self::assertSame($first, $second);
        self::assertSame(1, $created);
        self::assertSame(1, $second->queries);
    }

    #[Test]
    public function it_replaces_stale_connection(): void
    {
        $connections = [
            new StalePdo(),
            new HealthyPdo(),
        ];

        $pool = new SingleConnectionPool(static function () use (&$connections): \PDO {
            $connection = array_shift($connections);

            if (! $connection instanceof \PDO) {
                throw new \RuntimeException('No test connection available.');
            }

            return $connection;
        });

        $stale = $pool->pop();
        $pool->push($stale);

        $fresh = $pool->pop();

        self::assertNotSame($stale, $fresh);
        self::assertInstanceOf(HealthyPdo::class, $fresh);
    }

    #[Test]
    public function it_replaces_connection_when_health_check_returns_false(): void
    {
        $connections = [
            new FalseHealthCheckPdo(),
            new HealthyPdo(),
        ];

        $pool = new SingleConnectionPool(static function () use (&$connections): \PDO {
            $connection = array_shift($connections);

            if (! $connection instanceof \PDO) {
                throw new \RuntimeException('No test connection available.');
            }

            return $connection;
        });

        $stale = $pool->pop();
        $pool->push($stale);

        $fresh = $pool->pop();

        self::assertNotSame($stale, $fresh);
        self::assertInstanceOf(HealthyPdo::class, $fresh);
    }
}

final class HealthyPdo extends \PDO
{
    public int $queries = 0;

    public function __construct()
    {
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        ++$this->queries;

        return new HealthyPdoStatement();
    }
}

final class HealthyPdoStatement extends \PDOStatement
{
    public function __construct()
    {
    }
}

final class StalePdo extends \PDO
{
    public function __construct()
    {
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        throw new \PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
    }
}

final class FalseHealthCheckPdo extends \PDO
{
    public function __construct()
    {
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        return false;
    }
}

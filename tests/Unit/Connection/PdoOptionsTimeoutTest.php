<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Connection;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\DriverErrorClassifier;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\Exception\QueryTimeoutException;
use Semitexa\Orm\OrmManager;

/**
 * Without a connect timeout, a hung/unreachable MySQL parks the connecting
 * coroutine indefinitely while it holds a claimed pool slot — the pop()
 * timeout only protects waiters, never the holder. The query ceiling rides
 * MySQL's server-side max_execution_time because pdo_mysql exposes no
 * client-side read timeout.
 */
final class PdoOptionsTimeoutTest extends TestCase
{
    #[Test]
    public function the_connect_timeout_is_always_set_by_default(): void
    {
        $options = OrmManager::pdoOptions(connectTimeout: 5.0, queryTimeout: 0.0);

        self::assertSame(5, $options[\PDO::ATTR_TIMEOUT]);
        self::assertArrayNotHasKey(
            \PDO::MYSQL_ATTR_INIT_COMMAND,
            $options,
            'a zero query timeout must not install an init command',
        );
    }

    #[Test]
    public function a_query_timeout_becomes_a_server_side_execution_ceiling(): void
    {
        $options = OrmManager::pdoOptions(connectTimeout: 3.5, queryTimeout: 2.5);

        self::assertSame(4, $options[\PDO::ATTR_TIMEOUT], 'sub-second connect timeouts round UP, never to zero');
        self::assertSame(
            'SET SESSION MAX_EXECUTION_TIME=2500',
            $options[\PDO::MYSQL_ATTR_INIT_COMMAND],
            'seconds must translate to MySQL milliseconds',
        );
    }

    #[Test]
    public function connection_config_defaults_and_reads_the_timeout_envs(): void
    {
        $defaults = new ConnectionConfig();
        self::assertSame(5.0, $defaults->connectTimeout);
        self::assertSame(0.0, $defaults->queryTimeout, 'the query ceiling ships opt-in — existing deployments must not change behavior');

        $explicit = new ConnectionConfig(connectTimeout: 2.0, queryTimeout: 30.0);
        self::assertSame(2.0, $explicit->connectTimeout);
        self::assertSame(30.0, $explicit->queryTimeout);
    }

    #[Test]
    public function a_killed_query_classifies_as_query_timeout(): void
    {
        $pdoException = new \PDOException('Query execution was interrupted, maximum statement execution time exceeded');
        $pdoException->errorInfo = ['HY000', 3024, 'maximum statement execution time exceeded'];

        $classified = DriverErrorClassifier::classify($pdoException);

        self::assertInstanceOf(QueryTimeoutException::class, $classified);
        self::assertSame(3024, $classified->driverCode);
        self::assertTrue($classified->isTransient());
    }
}

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
    public function the_query_ceiling_is_never_an_init_command(): void
    {
        // An init command naming an unknown variable fails INSIDE the PDO
        // constructor, so a MariaDB deployment would lose every connection the
        // moment DB_QUERY_TIMEOUT is set. The ceiling is applied after connect,
        // where the server flavor is known from the handshake.
        $options = OrmManager::pdoOptions(connectTimeout: 3.5, queryTimeout: 2.5);

        self::assertSame(4, $options[\PDO::ATTR_TIMEOUT], 'sub-second connect timeouts round UP, never to zero');
        self::assertArrayNotHasKey(
            \PDO::MYSQL_ATTR_INIT_COMMAND,
            $options,
            'the query ceiling must not ride an init command — it fails at connect on the wrong flavor',
        );
    }

    #[Test]
    public function applying_the_ceiling_picks_the_statement_by_server_flavor(): void
    {
        $mysql = new ServerVersionRecordingPdo('8.0.35-log');
        OrmManager::applyQueryTimeout($mysql, 2.5);
        self::assertSame(
            ['SET SESSION max_execution_time=2500'],
            $mysql->executed,
            'MySQL takes max_execution_time in milliseconds',
        );

        $mariadb = new ServerVersionRecordingPdo('10.11.2-MariaDB');
        OrmManager::applyQueryTimeout($mariadb, 2.5);
        self::assertSame(
            ['SET SESSION max_statement_time=2.500000'],
            $mariadb->executed,
            'MariaDB takes max_statement_time in seconds',
        );

        $ancient = new ServerVersionRecordingPdo('5.6.51');
        OrmManager::applyQueryTimeout($ancient, 2.5);
        self::assertSame([], $ancient->executed, 'a server without the variable must not be sent the statement');

        $off = new ServerVersionRecordingPdo('8.0.35');
        OrmManager::applyQueryTimeout($off, 0.0);
        self::assertSame([], $off->executed, 'a disabled ceiling issues nothing');
    }

    #[Test]
    public function a_server_that_rejects_the_ceiling_still_yields_a_usable_connection(): void
    {
        $refusing = new ServerVersionRecordingPdo('8.0.35', refuse: true);

        OrmManager::applyQueryTimeout($refusing, 2.5);

        // No exception: a ceiling that cannot be installed is a degraded
        // safeguard, not a reason to fail the connection.
        self::assertSame(['SET SESSION max_execution_time=2500'], $refusing->attempted);
    }

    #[Test]
    public function a_blank_or_malformed_timeout_falls_back_to_the_default(): void
    {
        // PDO reads a timeout of 0 as "wait forever", so a typo or a
        // present-but-empty DB_CONNECT_TIMEOUT must not silently disable the
        // connect timeout — that is exactly the hung-server failure it exists
        // to prevent.
        self::assertSame(5.0, ConnectionConfig::parseTimeoutValue(null, 5.0));
        self::assertSame(5.0, ConnectionConfig::parseTimeoutValue('', 5.0));
        self::assertSame(5.0, ConnectionConfig::parseTimeoutValue('   ', 5.0));
        self::assertSame(5.0, ConnectionConfig::parseTimeoutValue('abc', 5.0));
        self::assertSame(5.0, ConnectionConfig::parseTimeoutValue('-1', 5.0));

        // A deliberate, numeric value is honored — including an explicit zero.
        self::assertSame(2.5, ConnectionConfig::parseTimeoutValue('2.5', 5.0));
        self::assertSame(0.0, ConnectionConfig::parseTimeoutValue('0', 5.0));
        self::assertSame(3.0, ConnectionConfig::parseTimeoutValue(' 3 ', 5.0));
    }

    #[Test]
    public function a_sub_millisecond_ceiling_never_rounds_down_to_no_limit(): void
    {
        // max_execution_time=0 means "no limit" in MySQL, so rounding a tiny
        // ceiling to zero would hand the operator the opposite of what they
        // configured.
        $pdo = new ServerVersionRecordingPdo('8.0.35');
        OrmManager::applyQueryTimeout($pdo, 0.0004);

        self::assertSame(['SET SESSION max_execution_time=1'], $pdo->executed);
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

/** A PDO that reports a chosen server version and records the statements it is asked to run. */
final class ServerVersionRecordingPdo extends \PDO
{
    /** @var string[] */
    public array $executed = [];

    /** @var string[] */
    public array $attempted = [];

    public function __construct(
        private readonly string $serverVersion,
        private readonly bool $refuse = false,
    ) {
        parent::__construct('sqlite::memory:');
    }

    public function getAttribute(int $attribute): mixed
    {
        if ($attribute === \PDO::ATTR_SERVER_VERSION) {
            return $this->serverVersion;
        }

        return parent::getAttribute($attribute);
    }

    public function exec(string $statement): int|false
    {
        $this->attempted[] = $statement;

        if ($this->refuse) {
            throw new \PDOException('Unknown system variable');
        }

        $this->executed[] = $statement;

        return 0;
    }
}

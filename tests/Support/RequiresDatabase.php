<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Support;

use Semitexa\Core\Environment;
use Semitexa\Orm\OrmManager;

/**
 * The database the application is configured for, as these tests need it.
 *
 * Not configured is a skip: MySQL (the default driver) with no host, read
 * as OrmManager reads it (DB_CLI_HOST outside Docker when set, else DB_HOST;
 * process environment or .env), is the suite run outside the stack. Configured and not answering is a failure: each test used
 * to skip there too, so a database the stack could not reach left the suite
 * green over everything these tests exist to prove.
 */
trait RequiresDatabase
{
    private static function configuredOrm(): OrmManager
    {
        $orm = new OrmManager();
        [$name, $host] = self::configuredHost();
        if ($orm->getDriver() === 'mysql' && ($host === null || $host === '')) {
            self::markTestSkipped('DB_HOST not configured — the test requires the real database');
        }

        try {
            $orm->getAdapter()->execute('SELECT 1');
        } catch (\Throwable $e) {
            $where = $orm->getDriver() === 'mysql' ? "{$name}={$host}" : 'DB_DRIVER=' . $orm->getDriver();
            self::fail("{$where} is configured, but the database does not answer: " . $e->getMessage());
        }

        return $orm;
    }

    /**
     * The variable OrmManager::resolveDbHost() connects with, and its value.
     *
     * @return array{string, ?string}
     */
    private static function configuredHost(): array
    {
        $cliHost = Environment::getEnvValue('DB_CLI_HOST');
        if (!file_exists('/.dockerenv') && $cliHost !== null && $cliHost !== '') {
            return ['DB_CLI_HOST', $cliHost];
        }

        return ['DB_HOST', Environment::getEnvValue('DB_HOST')];
    }

    /** As configuredOrm(), for a test about MySQL itself: another driver is a skip. */
    private static function mysqlOrm(string $why): OrmManager
    {
        if (new OrmManager()->getDriver() !== 'mysql') {
            self::markTestSkipped("not MySQL: {$why}");
        }

        return self::configuredOrm();
    }
}

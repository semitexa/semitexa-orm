<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Connection;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Connection\ConnectionConfig;

final class ConnectionConfigTest extends TestCase
{
    /**
     * @param list<string> $keys
     * @return array<string, string|false>
     */
    private function snapshotEnv(array $keys): array
    {
        $snapshot = [];

        foreach ($keys as $key) {
            $snapshot[$key] = getenv($key);
        }

        return $snapshot;
    }

    /**
     * @param array<string, string|false> $snapshot
     */
    private function restoreEnv(array $snapshot): void
    {
        foreach ($snapshot as $key => $value) {
            if ($value === false) {
                putenv($key);
                continue;
            }

            putenv("{$key}={$value}");
        }
    }

    #[Test]
    public function defaults_are_sane(): void
    {
        $config = new ConnectionConfig();

        $this->assertSame('mysql', $config->driver);
        $this->assertSame('127.0.0.1', $config->host);
        $this->assertSame('3306', $config->port);
        $this->assertSame('semitexa', $config->database);
        $this->assertSame('root', $config->username);
        $this->assertSame('', $config->password);
        $this->assertSame('utf8mb4', $config->charset);
        $this->assertSame(10, $config->poolSize);
        $this->assertNull($config->sqlitePath);
        $this->assertFalse($config->sqliteMemory);
        $this->assertNull($config->cliHost);
        $this->assertNull($config->cliPort);
    }

    #[Test]
    public function constructor_accepts_all_parameters(): void
    {
        $config = new ConnectionConfig(
            driver: 'sqlite',
            host: 'db.example.com',
            port: '5432',
            database: 'analytics',
            username: 'analyst',
            password: 'secret',
            charset: 'utf8',
            poolSize: 5,
            sqlitePath: '/tmp/analytics.sqlite',
            sqliteMemory: true,
            cliHost: 'localhost',
            cliPort: '33060',
        );

        $this->assertSame('sqlite', $config->driver);
        $this->assertSame('db.example.com', $config->host);
        $this->assertSame('5432', $config->port);
        $this->assertSame('analytics', $config->database);
        $this->assertSame('analyst', $config->username);
        $this->assertSame('secret', $config->password);
        $this->assertSame('utf8', $config->charset);
        $this->assertSame(5, $config->poolSize);
        $this->assertSame('/tmp/analytics.sqlite', $config->sqlitePath);
        $this->assertTrue($config->sqliteMemory);
        $this->assertSame('localhost', $config->cliHost);
        $this->assertSame('33060', $config->cliPort);
    }

    #[Test]
    public function from_environment_default_uses_unprefixed_vars(): void
    {
        $snapshot = $this->snapshotEnv([
            'DB_DRIVER',
            'DB_HOST',
            'DB_PORT',
            'DB_DATABASE',
            'DB_USERNAME',
            'DB_PASSWORD',
            'DB_CHARSET',
            'DB_POOL_SIZE',
        ]);

        // Set unprefixed DB_* vars
        putenv('DB_DRIVER=mysql');
        putenv('DB_HOST=testhost');
        putenv('DB_PORT=3307');
        putenv('DB_DATABASE=testdb');
        putenv('DB_USERNAME=testuser');
        putenv('DB_PASSWORD=testpass');
        putenv('DB_CHARSET=latin1');
        putenv('DB_POOL_SIZE=20');

        try {
            $config = ConnectionConfig::fromEnvironment('default');

            $this->assertSame('mysql', $config->driver);
            $this->assertSame('testhost', $config->host);
            $this->assertSame('3307', $config->port);
            $this->assertSame('testdb', $config->database);
            $this->assertSame('testuser', $config->username);
            $this->assertSame('testpass', $config->password);
            $this->assertSame('latin1', $config->charset);
            $this->assertSame(20, $config->poolSize);
        } finally {
            $this->restoreEnv($snapshot);
        }
    }

    #[Test]
    public function from_environment_named_uses_prefixed_vars(): void
    {
        $snapshot = $this->snapshotEnv([
            'DB_ANALYTICS_DRIVER',
            'DB_ANALYTICS_HOST',
            'DB_ANALYTICS_DATABASE',
            'DB_ANALYTICS_SQLITE_PATH',
            'DB_ANALYTICS_SQLITE_MEMORY',
        ]);

        putenv('DB_ANALYTICS_DRIVER=sqlite');
        putenv('DB_ANALYTICS_HOST=analytics.db');
        putenv('DB_ANALYTICS_DATABASE=analytics_db');
        putenv('DB_ANALYTICS_SQLITE_PATH=/tmp/analytics.sqlite');
        putenv('DB_ANALYTICS_SQLITE_MEMORY=true');

        try {
            $config = ConnectionConfig::fromEnvironment('analytics');

            $this->assertSame('sqlite', $config->driver);
            $this->assertSame('analytics.db', $config->host);
            $this->assertSame('analytics_db', $config->database);
            $this->assertSame('/tmp/analytics.sqlite', $config->sqlitePath);
            $this->assertTrue($config->sqliteMemory);
        } finally {
            $this->restoreEnv($snapshot);
        }
    }

    #[Test]
    public function from_environment_converts_hyphens_to_underscores(): void
    {
        $snapshot = $this->snapshotEnv([
            'DB_PRIMARY_READ_DRIVER',
            'DB_PRIMARY_READ_HOST',
        ]);

        putenv('DB_PRIMARY_READ_DRIVER=mysql');
        putenv('DB_PRIMARY_READ_HOST=replica.db');

        try {
            $config = ConnectionConfig::fromEnvironment('primary-read');

            $this->assertSame('mysql', $config->driver);
            $this->assertSame('replica.db', $config->host);
        } finally {
            $this->restoreEnv($snapshot);
        }
    }
}

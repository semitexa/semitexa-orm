<?php

declare(strict_types=1);

namespace Semitexa\Orm\Domain\Model;

use Semitexa\Core\Environment;

final readonly class ConnectionConfig
{
    public function __construct(
        public string $driver = 'mysql',
        public string $host = '127.0.0.1',
        public string $port = '3306',
        public string $database = 'semitexa',
        public string $username = 'root',
        public string $password = '',
        public string $charset = 'utf8mb4',
        public int $poolSize = 10,
        // Seconds PDO waits for the TCP connect before failing. Without it a
        // hung/unreachable server parks the connecting coroutine indefinitely
        // while it holds a pool slot — the pop() timeout only protects
        // WAITERS, never the holder.
        public float $connectTimeout = 5.0,
        // Server-side ceiling for SELECT execution (MySQL max_execution_time),
        // seconds; 0 disables. Applied at connect via an init command, so it
        // rides every pooled connection. SELECT-only by MySQL semantics —
        // writes are already bounded by innodb_lock_wait_timeout.
        public float $queryTimeout = 0.0,
        public ?string $sqlitePath = null,
        public bool $sqliteMemory = false,
        public ?string $cliHost = null,
        public ?string $cliPort = null,
    ) {}

    /**
     * Build config from environment variables.
     *
     * 'default' uses unprefixed DB_* vars; named connections use DB_{NAME}_* vars.
     * Example: ConnectionConfig::fromEnvironment('analytics') reads DB_ANALYTICS_*.
     */
    public static function fromEnvironment(string $name = 'default'): self
    {
        $prefix = $name === 'default'
            ? 'DB_'
            : 'DB_' . strtoupper(str_replace('-', '_', $name)) . '_';

        $sqliteMemory = Environment::getEnvValue($prefix . 'SQLITE_MEMORY');
        $driver = Environment::getEnvValue($prefix . 'DRIVER', 'mysql') ?? 'mysql';
        $host = Environment::getEnvValue($prefix . 'HOST', '127.0.0.1') ?? '127.0.0.1';
        $port = Environment::getEnvValue($prefix . 'PORT', '3306') ?? '3306';
        $database = Environment::getEnvValue($prefix . 'DATABASE', 'semitexa') ?? 'semitexa';
        $username = Environment::getEnvValue($prefix . 'USERNAME')
            ?? Environment::getEnvValue($prefix . 'USER', 'root')
            ?? 'root';
        $password = Environment::getEnvValue($prefix . 'PASSWORD', '') ?? '';
        $charset = Environment::getEnvValue($prefix . 'CHARSET', 'utf8mb4') ?? 'utf8mb4';
        $poolSize = (int) (Environment::getEnvValue($prefix . 'POOL_SIZE', '10') ?? '10');
        $connectTimeout = (float) (Environment::getEnvValue($prefix . 'CONNECT_TIMEOUT', '5') ?? '5');
        $queryTimeout = (float) (Environment::getEnvValue($prefix . 'QUERY_TIMEOUT', '0') ?? '0');

        return new self(
            driver: $driver,
            host: $host,
            port: $port,
            database: $database,
            username: $username,
            password: $password,
            charset: $charset,
            poolSize: $poolSize,
            connectTimeout: $connectTimeout,
            queryTimeout: $queryTimeout,
            sqlitePath: Environment::getEnvValue($prefix . 'SQLITE_PATH'),
            sqliteMemory: $sqliteMemory !== null && in_array(
                strtolower($sqliteMemory),
                ['1', 'true', 'yes'],
                true,
            ),
            cliHost: Environment::getEnvValue($prefix . 'CLI_HOST'),
            cliPort: Environment::getEnvValue($prefix . 'CLI_PORT'),
        );
    }
}

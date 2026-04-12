<?php

declare(strict_types=1);

namespace Semitexa\Orm\Connection;

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

        return new self(
            driver: Environment::getEnvValue($prefix . 'DRIVER', 'mysql'),
            host: Environment::getEnvValue($prefix . 'HOST', '127.0.0.1'),
            port: Environment::getEnvValue($prefix . 'PORT', '3306'),
            database: Environment::getEnvValue($prefix . 'DATABASE', 'semitexa'),
            username: Environment::getEnvValue($prefix . 'USERNAME')
                ?? Environment::getEnvValue($prefix . 'USER', 'root'),
            password: Environment::getEnvValue($prefix . 'PASSWORD', ''),
            charset: Environment::getEnvValue($prefix . 'CHARSET', 'utf8mb4'),
            poolSize: (int) Environment::getEnvValue($prefix . 'POOL_SIZE', '10'),
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

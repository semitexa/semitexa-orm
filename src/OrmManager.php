<?php

declare(strict_types=1);

namespace Semitexa\Orm;

use Semitexa\Core\Environment;
use Semitexa\Core\Util\ProjectRoot;
use Semitexa\Orm\Adapter\ConnectionPool;
use Semitexa\Orm\Adapter\ConnectionPoolInterface;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\MysqlAdapter;
use Semitexa\Orm\Adapter\SingleConnectionPool;
use Semitexa\Orm\Schema\SchemaCollector;
use Semitexa\Orm\Schema\SchemaComparator;
use Semitexa\Orm\Sync\AuditLogger;
use Semitexa\Orm\Sync\SeedRunner;
use Semitexa\Orm\Sync\SyncEngine;
use Semitexa\Orm\Transaction\TransactionManager;

class OrmManager
{
    private ?ConnectionPoolInterface $pool = null;
    private ?DatabaseAdapterInterface $adapter = null;
    private ?SchemaCollector $schemaCollector = null;
    private ?SchemaComparator $schemaComparator = null;
    private ?SyncEngine $syncEngine = null;
    private ?TransactionManager $transactionManager = null;
    private ?SeedRunner $seedRunner = null;

    public function getAdapter(): DatabaseAdapterInterface
    {
        if ($this->adapter === null) {
            $this->adapter = new MysqlAdapter($this->getPool());
        }

        return $this->adapter;
    }

    public function getPool(): ConnectionPoolInterface
    {
        if ($this->pool === null) {
            $this->pool = $this->createPool();
        }

        return $this->pool;
    }

    public function getSchemaCollector(): SchemaCollector
    {
        if ($this->schemaCollector === null) {
            $this->schemaCollector = new SchemaCollector();
        }

        return $this->schemaCollector;
    }

    public function getSchemaComparator(): SchemaComparator
    {
        if ($this->schemaComparator === null) {
            $this->schemaComparator = new SchemaComparator(
                $this->getAdapter(),
                $this->getDatabaseName(),
                $this->resolveIgnoreTables(),
            );
        }

        return $this->schemaComparator;
    }

    public function getSyncEngine(): SyncEngine
    {
        if ($this->syncEngine === null) {
            $historyDir = ProjectRoot::get() . '/var/migrations/history';
            $this->syncEngine = new SyncEngine(
                $this->getAdapter(),
                new AuditLogger($historyDir),
            );
        }

        return $this->syncEngine;
    }

    public function getTransactionManager(): TransactionManager
    {
        if ($this->transactionManager === null) {
            $this->transactionManager = new TransactionManager(
                $this->getPool(),
                $this->getAdapter(),
            );
        }

        return $this->transactionManager;
    }

    public function getSeedRunner(): SeedRunner
    {
        if ($this->seedRunner === null) {
            $this->seedRunner = new SeedRunner($this->getAdapter());
        }

        return $this->seedRunner;
    }

    public function getDatabaseName(): string
    {
        return Environment::getEnvValue('DB_DATABASE', 'semitexa');
    }

    public function shutdown(): void
    {
        $this->pool?->close();
        $this->pool = null;
        $this->adapter = null;
    }

    public function __destruct()
    {
        $this->shutdown();
    }

    /**
     * Run a callback with a managed OrmManager instance.
     * shutdown() is guaranteed via finally — even if the callback throws.
     *
     * @template T
     * @param callable(OrmManager): T $callback
     * @return T
     */
    public static function run(callable $callback): mixed
    {
        $orm = new self();
        try {
            return $callback($orm);
        } finally {
            $orm->shutdown();
        }
    }

    /**
     * @return string[]
     */
    private function resolveIgnoreTables(): array
    {
        $raw = Environment::getEnvValue('ORM_IGNORE_TABLES', '');
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    private function createPool(): ConnectionPoolInterface
    {
        // Ensure .env is loaded in Swoole workers (getenv may be empty after fork)
        if (defined('SEMITEXA_SWOOLE') && SEMITEXA_SWOOLE && class_exists(Environment::class)) {
            ProjectRoot::reset();
            Environment::syncEnvFromFiles();
        }

        $host = $this->resolveDbHost();
        $port = $this->resolveDbPort();
        $database = Environment::getEnvValue('DB_DATABASE', 'semitexa');
        $username = Environment::getEnvValue('DB_USERNAME') ?? Environment::getEnvValue('DB_USER', 'root');
        $password = Environment::getEnvValue('DB_PASSWORD', '');
        $charset = Environment::getEnvValue('DB_CHARSET', 'utf8mb4');
        $poolSize = (int) Environment::getEnvValue('DB_POOL_SIZE', '10');

        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";

        $factory = static function () use ($dsn, $username, $password): \PDO {
            $pdo = new \PDO($dsn, $username, $password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            return $pdo;
        };

        if (class_exists(\Swoole\Coroutine\Channel::class, false)) {
            return new ConnectionPool($poolSize, $factory);
        }

        return new SingleConnectionPool($factory);
    }

    /** When running on host (CLI), use DB_CLI_* so GUI/CLI connect to host port; inside Docker use DB_HOST/DB_PORT. */
    private function resolveDbHost(): string
    {
        if (!$this->isRunningInDocker()) {
            $cliHost = Environment::getEnvValue('DB_CLI_HOST');
            if ($cliHost !== null && $cliHost !== '') {
                return $cliHost;
            }
        }
        return Environment::getEnvValue('DB_HOST', '127.0.0.1');
    }

    private function resolveDbPort(): string
    {
        if (!$this->isRunningInDocker()) {
            $cliPort = Environment::getEnvValue('DB_CLI_PORT');
            if ($cliPort !== null && $cliPort !== '') {
                return $cliPort;
            }
        }
        return Environment::getEnvValue('DB_PORT', '3306');
    }

    private function isRunningInDocker(): bool
    {
        return file_exists('/.dockerenv');
    }
}

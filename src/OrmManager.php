<?php

declare(strict_types=1);

namespace Semitexa\Orm;

use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Environment;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Orm\Adapter\ConnectionPool;
use Semitexa\Orm\Adapter\ConnectionPoolInterface;
use Semitexa\Orm\Connection\ConnectionConfig;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\MysqlAdapter;
use Semitexa\Orm\Adapter\NullConnectionPool;
use Semitexa\Orm\Adapter\SingleConnectionPool;
use Semitexa\Orm\Adapter\SqliteAdapter;
use Semitexa\Orm\Schema\SqliteSchemaComparator;
use Semitexa\Orm\Bootstrap\OrmBootstrapReport;
use Semitexa\Orm\Bootstrap\OrmBootstrapValidator;
use Semitexa\Orm\Hydration\ResourceModelHydrator;
use Semitexa\Orm\Hydration\ResourceModelRelationLoader;
use Semitexa\Orm\Mapping\MapperRegistry;
use Semitexa\Orm\Metadata\ResourceModelMetadataRegistry;
use Semitexa\Orm\Persistence\AggregateWriteEngine;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Orm\Schema\SchemaCollector;
use Semitexa\Orm\Schema\SchemaComparator;
use Semitexa\Orm\Schema\SchemaComparatorInterface;
use Semitexa\Orm\Sync\AuditLogger;
use Semitexa\Orm\Sync\SeedRunner;
use Semitexa\Orm\Sync\SyncEngine;
use Semitexa\Orm\Transaction\TransactionManager;

class OrmManager
{
    private ClassDiscovery $classDiscovery;
    private ?ConnectionPoolInterface $pool = null;
    private ?DatabaseAdapterInterface $adapter = null;
    private ?SchemaCollector $schemaCollector = null;
    private ?SchemaComparatorInterface $schemaComparator = null;
    private ?SyncEngine $syncEngine = null;
    private ?TransactionManager $transactionManager = null;
    private ?SeedRunner $seedRunner = null;
    private ?MapperRegistry $mapperRegistry = null;
    private ?ResourceModelMetadataRegistry $resourceModelMetadataRegistry = null;
    private ?ResourceModelHydrator $resourceModelHydrator = null;
    private ?ResourceModelRelationLoader $resourceModelRelationLoader = null;
    private ?AggregateWriteEngine $aggregateWriteEngine = null;
    private ?OrmBootstrapValidator $bootstrapValidator = null;

    public function __construct(
        ?ClassDiscovery $classDiscovery = null,
        private readonly ?ConnectionConfig $config = null,
        private readonly string $connectionName = 'default',
    ) {
        $this->classDiscovery = $classDiscovery ?? new ClassDiscovery();
    }

    public function getClassDiscovery(): ClassDiscovery
    {
        return $this->classDiscovery;
    }

    public function getAdapter(): DatabaseAdapterInterface
    {
        if ($this->adapter === null) {
            $driver = $this->resolveDriver();

            if ($driver === 'sqlite') {
                $this->adapter = $this->createSqliteAdapter();
            } else {
                $this->adapter = new MysqlAdapter($this->getPool());
            }
        }

        return $this->adapter;
    }

    public function getPool(): ConnectionPoolInterface
    {
        if ($this->pool === null) {
            $driver = $this->resolveDriver();

            // SQLite doesn't need connection pooling
            if ($driver === 'sqlite') {
                throw new \LogicException('getPool() is not applicable for SQLite adapter. Use getAdapter() directly.');
            }

            $this->pool = $this->createPool();
        }

        return $this->pool;
    }

    public function getSchemaCollector(): SchemaCollector
    {
        if ($this->schemaCollector === null) {
            $this->schemaCollector = new SchemaCollector(
                $this->classDiscovery,
                $this->resolveDriver(),
                $this->connectionName,
            );
        }

        return $this->schemaCollector;
    }

    public function getSchemaComparator(): SchemaComparatorInterface
    {
        if ($this->schemaComparator === null) {
            if ($this->resolveDriver() === 'sqlite') {
                $this->schemaComparator = new SqliteSchemaComparator(
                    $this->getAdapter(),
                    $this->resolveIgnoreTables(),
                );
            } else {
                $this->schemaComparator = new SchemaComparator(
                    $this->getAdapter(),
                    $this->getDatabaseName(),
                    $this->resolveIgnoreTables(),
                );
            }
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
            $driver = $this->resolveDriver();

            // SQLite: TransactionManager takes a dedicated SQLite code path
            // and does not actually consult the pool — supply a named
            // NullConnectionPool so any accidental pop() throws loudly.
            $pool = $driver === 'sqlite'
                ? new NullConnectionPool()
                : $this->getPool();

            $this->transactionManager = new TransactionManager(
                $pool,
                $this->getAdapter(),
            );
        }

        return $this->transactionManager;
    }

    public function getSeedRunner(): SeedRunner
    {
        if ($this->seedRunner === null) {
            $this->seedRunner = new SeedRunner($this->getAdapter(), $this->classDiscovery);
        }

        return $this->seedRunner;
    }

    public function getMapperRegistry(): MapperRegistry
    {
        if ($this->mapperRegistry === null) {
            $this->mapperRegistry = new MapperRegistry($this->classDiscovery);
            $this->mapperRegistry->build();
        }

        return $this->mapperRegistry;
    }

    public function getResourceModelMetadataRegistry(): ResourceModelMetadataRegistry
    {
        if ($this->resourceModelMetadataRegistry === null) {
            $this->resourceModelMetadataRegistry = new ResourceModelMetadataRegistry();
        }

        return $this->resourceModelMetadataRegistry;
    }

    public function getResourceModelHydrator(): ResourceModelHydrator
    {
        if ($this->resourceModelHydrator === null) {
            $this->resourceModelHydrator = new ResourceModelHydrator(
                metadataRegistry: $this->getResourceModelMetadataRegistry(),
            );
        }

        return $this->resourceModelHydrator;
    }

    public function getResourceModelRelationLoader(): ResourceModelRelationLoader
    {
        if ($this->resourceModelRelationLoader === null) {
            $this->resourceModelRelationLoader = new ResourceModelRelationLoader(
                $this->getAdapter(),
                $this->getResourceModelHydrator(),
                $this->getResourceModelMetadataRegistry(),
            );
        }

        return $this->resourceModelRelationLoader;
    }

    public function getAggregateWriteEngine(): AggregateWriteEngine
    {
        if ($this->aggregateWriteEngine === null) {
            $this->aggregateWriteEngine = new AggregateWriteEngine(
                $this->getAdapter(),
                $this->getResourceModelHydrator(),
                $this->getResourceModelMetadataRegistry(),
            );
        }

        return $this->aggregateWriteEngine;
    }

    public function getBootstrapValidator(): OrmBootstrapValidator
    {
        if ($this->bootstrapValidator === null) {
            $this->bootstrapValidator = new OrmBootstrapValidator(
                classDiscovery: $this->classDiscovery,
                metadataRegistry: $this->getResourceModelMetadataRegistry(),
                mapperRegistry: $this->getMapperRegistry(),
            );
        }

        return $this->bootstrapValidator;
    }

    public function validateBootstrap(): OrmBootstrapReport
    {
        return $this->getBootstrapValidator()->validate();
    }

    /**
     * @param class-string $resourceModelClass
     * @param class-string $domainModelClass
     */
    public function repository(string $resourceModelClass, string $domainModelClass): DomainRepository
    {
        return new DomainRepository(
            resourceModelClass: $resourceModelClass,
            domainModelClass: $domainModelClass,
            adapter: $this->getAdapter(),
            mapperRegistry: $this->getMapperRegistry(),
            hydrator: $this->getResourceModelHydrator(),
            relationLoader: $this->getResourceModelRelationLoader(),
            metadataRegistry: $this->getResourceModelMetadataRegistry(),
            writeEngine: $this->getAggregateWriteEngine(),
        );
    }

    public function getDatabaseName(): string
    {
        if ($this->config !== null) {
            if ($this->config->driver === 'sqlite') {
                return $this->config->sqliteMemory
                    ? ':memory:'
                    : ($this->config->sqlitePath ?? 'sqlite');
            }

            return $this->config->database;
        }

        if ($this->resolveDriver() === 'sqlite') {
            $memory = Environment::getEnvValue('DB_SQLITE_MEMORY');
            if (in_array(strtolower((string) $memory), ['1', 'true', 'yes'], true)) {
                return ':memory:';
            }

            return Environment::getEnvValue('DB_SQLITE_PATH', ProjectRoot::get() . '/var/database/semitexa.sqlite')
                ?? ProjectRoot::get() . '/var/database/semitexa.sqlite';
        }

        return Environment::getEnvValue('DB_DATABASE', 'semitexa') ?? 'semitexa';
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
        // Ensure Swoole workers/coroutines resolve DB_* values from the project env files.
        if (class_exists(\Swoole\Coroutine::class, false)) {
            ProjectRoot::reset();
            Environment::syncEnvFromFiles();
        }

        if ($this->config !== null) {
            $host = $this->config->cliHost && !$this->isRunningInDocker()
                ? $this->config->cliHost
                : $this->config->host;
            $port = $this->config->cliPort && !$this->isRunningInDocker()
                ? $this->config->cliPort
                : $this->config->port;
            $database = $this->config->database;
            $username = $this->config->username;
            $password = $this->config->password;
            $charset = $this->config->charset;
            $poolSize = $this->config->poolSize;
        } else {
            $host = $this->resolveDbHost();
            $port = $this->resolveDbPort();
            $database = Environment::getEnvValue('DB_DATABASE', 'semitexa');
            $username = Environment::getEnvValue('DB_USERNAME') ?? Environment::getEnvValue('DB_USER', 'root');
            $password = Environment::getEnvValue('DB_PASSWORD', '');
            $charset = Environment::getEnvValue('DB_CHARSET', 'utf8mb4');
            $poolSize = (int) Environment::getEnvValue('DB_POOL_SIZE', '10');
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";

        $factory = static function () use ($dsn, $username, $password): \PDO {
            $pdo = new \PDO($dsn, $username, $password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            return $pdo;
        };

        // Use ConnectionPool only inside a Swoole coroutine (e.g. request handler). CLI has no coroutine.
        if (
            class_exists(\Swoole\Coroutine\Channel::class, false)
            && class_exists(\Swoole\Coroutine::class, false)
            && \Swoole\Coroutine::getCid() >= 0
        ) {
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

    /**
     * Resolve the database driver from environment configuration.
     * Defaults to 'mysql' for backward compatibility.
     */
    private function resolveDriver(): string
    {
        $driverSource = $this->config !== null
            ? $this->config->driver
            : (Environment::getEnvValue('DB_DRIVER', 'mysql') ?? 'mysql');
        $driver = strtolower($driverSource);

        return match ($driver) {
            'mysql', 'sqlite' => $driver,
            default => throw new \InvalidArgumentException(
                "Unsupported DB driver '{$driver}'. Expected 'mysql' or 'sqlite'.",
            ),
        };
    }

    /**
     * Create a SQLite adapter based on environment configuration.
     *
     * Supports:
     * - DB_SQLITE_PATH: absolute or relative path to SQLite file
     * - DB_SQLITE_MEMORY: if set to "1" or "true", use in-memory database
     */
    private function createSqliteAdapter(): SqliteAdapter
    {
        if ($this->config !== null) {
            if ($this->config->sqliteMemory) {
                return new SqliteAdapter('sqlite::memory:');
            }

            $path = $this->config->sqlitePath;
            if ($path === null || $path === '') {
                $path = ProjectRoot::get() . '/var/database/semitexa.sqlite';
            }
        } else {
            $memory = Environment::getEnvValue('DB_SQLITE_MEMORY');
            if (in_array(strtolower((string) $memory), ['1', 'true', 'yes'], true)) {
                return new SqliteAdapter('sqlite::memory:');
            }

            $path = Environment::getEnvValue('DB_SQLITE_PATH');
            if ($path === null || $path === '') {
                $path = ProjectRoot::get() . '/var/database/semitexa.sqlite';
            }
        }

        // Ensure directory exists
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return new SqliteAdapter("sqlite:{$path}");
    }
}

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
use Semitexa\Orm\Bootstrap\OrmBootstrapReport;
use Semitexa\Orm\Bootstrap\OrmBootstrapValidator;
use Semitexa\Orm\Hydration\TableModelHydrator;
use Semitexa\Orm\Hydration\TableModelRelationLoader;
use Semitexa\Orm\Mapping\MapperRegistry;
use Semitexa\Orm\Metadata\TableModelMetadataRegistry;
use Semitexa\Orm\Persistence\AggregateWriteEngine;
use Semitexa\Orm\Repository\DomainRepository;
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
    private ?MapperRegistry $mapperRegistry = null;
    private ?TableModelMetadataRegistry $tableModelMetadataRegistry = null;
    private ?TableModelHydrator $tableModelHydrator = null;
    private ?TableModelRelationLoader $tableModelRelationLoader = null;
    private ?AggregateWriteEngine $aggregateWriteEngine = null;
    private ?OrmBootstrapValidator $bootstrapValidator = null;

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

    public function getMapperRegistry(): MapperRegistry
    {
        if ($this->mapperRegistry === null) {
            $this->mapperRegistry = new MapperRegistry();
            $this->mapperRegistry->build();
        }

        return $this->mapperRegistry;
    }

    public function getTableModelMetadataRegistry(): TableModelMetadataRegistry
    {
        if ($this->tableModelMetadataRegistry === null) {
            $this->tableModelMetadataRegistry = new TableModelMetadataRegistry();
        }

        return $this->tableModelMetadataRegistry;
    }

    public function getTableModelHydrator(): TableModelHydrator
    {
        if ($this->tableModelHydrator === null) {
            $this->tableModelHydrator = new TableModelHydrator(
                metadataRegistry: $this->getTableModelMetadataRegistry(),
            );
        }

        return $this->tableModelHydrator;
    }

    public function getTableModelRelationLoader(): TableModelRelationLoader
    {
        if ($this->tableModelRelationLoader === null) {
            $this->tableModelRelationLoader = new TableModelRelationLoader(
                $this->getAdapter(),
                $this->getTableModelHydrator(),
                $this->getTableModelMetadataRegistry(),
            );
        }

        return $this->tableModelRelationLoader;
    }

    public function getAggregateWriteEngine(): AggregateWriteEngine
    {
        if ($this->aggregateWriteEngine === null) {
            $this->aggregateWriteEngine = new AggregateWriteEngine(
                $this->getAdapter(),
                $this->getTableModelHydrator(),
                $this->getTableModelMetadataRegistry(),
            );
        }

        return $this->aggregateWriteEngine;
    }

    public function getBootstrapValidator(): OrmBootstrapValidator
    {
        if ($this->bootstrapValidator === null) {
            $this->bootstrapValidator = new OrmBootstrapValidator(
                metadataRegistry: $this->getTableModelMetadataRegistry(),
                mapperRegistry: $this->getMapperRegistry(),
            );
        }

        return $this->bootstrapValidator;
    }

    public function validateBootstrap(): OrmBootstrapReport
    {
        return $this->getBootstrapValidator()->validate();
    }

    public function repository(string $tableModelClass, string $domainModelClass): DomainRepository
    {
        return new DomainRepository(
            tableModelClass: $tableModelClass,
            domainModelClass: $domainModelClass,
            adapter: $this->getAdapter(),
            mapperRegistry: $this->getMapperRegistry(),
            hydrator: $this->getTableModelHydrator(),
            relationLoader: $this->getTableModelRelationLoader(),
            metadataRegistry: $this->getTableModelMetadataRegistry(),
            writeEngine: $this->getAggregateWriteEngine(),
        );
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
        // Ensure Swoole workers/coroutines resolve DB_* values from the project env files.
        if (class_exists(\Swoole\Coroutine::class, false)) {
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
}

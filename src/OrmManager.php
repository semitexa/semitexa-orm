<?php

declare(strict_types=1);

namespace Semitexa\Orm;

use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Environment;
use Semitexa\Core\Event\EventDispatcherInterface;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Orm\Adapter\ConnectionPool;
use Semitexa\Orm\Adapter\ConnectionPoolInterface;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\MysqlAdapter;
use Semitexa\Orm\Adapter\NullConnectionPool;
use Semitexa\Orm\Adapter\SingleConnectionPool;
use Semitexa\Orm\Adapter\SqliteAdapter;
use Semitexa\Orm\Application\Service\Schema\SqliteSchemaComparator;
use Semitexa\Orm\Domain\Model\OrmBootstrapReport;
use Semitexa\Orm\Application\Service\OrmBootstrapValidator;
use Semitexa\Orm\Application\Service\Hydration\ResourceModelHydrator;
use Semitexa\Orm\Application\Service\Hydration\ResourceModelRelationLoader;
use Semitexa\Orm\Application\Service\Mapping\MapperRegistry;
use Semitexa\Orm\Metadata\ResourceModelMetadataRegistry;
use Semitexa\Orm\Application\Service\Persistence\AggregateWriteEngine;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Orm\Application\Service\Schema\SchemaCollector;
use Semitexa\Orm\Application\Service\Schema\SchemaComparator;
use Semitexa\Orm\Domain\Contract\SchemaComparatorInterface;
use Semitexa\Orm\Application\Service\Sync\AuditLogger;
use Semitexa\Orm\Application\Service\Sync\SeedRunner;
use Semitexa\Orm\Application\Service\Sync\SyncEngine;
use Semitexa\Orm\Application\Service\Transaction\TransactionManager;

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

    /**
     * Lazy resolver for the default EventDispatcher, set once per worker at bootstrap
     * by the framework container (see ContainerFactory). It is invoked lazily — at
     * first write-engine construction — because the dispatcher is a discovered service
     * that only exists after the container is built, whereas the default OrmManager is
     * constructed during bootstrap (before build). This makes EVERY default OrmManager
     * carry the dispatcher: the explicit ConnectionRegistry::manager() instance AND any
     * bare `new OrmManager()` repository fallback — without a compile-time coupling from
     * orm to the core container.
     *
     * @var (\Closure(): ?EventDispatcherInterface)|null
     */
    private static ?\Closure $defaultEventDispatcherResolver = null;

    public function __construct(
        ?ClassDiscovery $classDiscovery = null,
        private readonly ?ConnectionConfig $config = null,
        private readonly string $connectionName = 'default',
        private readonly ?EventDispatcherInterface $events = null,
    ) {
        $this->classDiscovery = $classDiscovery ?? new ClassDiscovery();
    }

    public function getClassDiscovery(): ClassDiscovery
    {
        return $this->classDiscovery;
    }

    public function getAdapter(): DatabaseAdapterInterface
    {
        // A request-time getAdapter() may hold an adapter built at bootstrap over a
        // stale SingleConnectionPool. Re-check before handing it out: the upgrade
        // nulls $this->adapter so it rebuilds below over the coroutine-safe pool.
        if ($this->adapter !== null && $this->pool !== null) {
            $this->ensureCoroutineSafePool();
        }

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
        } else {
            $this->ensureCoroutineSafePool();
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
        // A memoized TransactionManager may wrap an adapter/pool built at bootstrap
        // over a stale SingleConnectionPool (before Swoole hooks were enabled). Like
        // getPool()/getAdapter(), re-check before handing it out so a request that
        // enters through the transaction path also self-heals onto the
        // coroutine-safe pool. ensureCoroutineSafePool() preserves the
        // active-transaction guard and nulls $this->transactionManager on a swap,
        // so the block below rebuilds it over the fresh pool + adapter.
        if ($this->transactionManager !== null) {
            $this->ensureCoroutineSafePool();
        }

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
            // Build FIRST, memoize LAST. build() walks the classmap through
            // ClassDiscovery, whose autoloads are file IO — a coroutine
            // SUSPENSION point under SWOOLE_HOOK_ALL. Memoizing the empty
            // registry before build() (the old order) let a concurrent
            // coroutine on the same manager observe a half-built registry and
            // die with MissingMapperException — reproduced as intermittent
            // 500s on the first concurrent burst after a worker boot. Losing
            // the ??= race is fine: both registries are complete, the first
            // one wins, the duplicate is GC'd.
            $registry = new MapperRegistry($this->classDiscovery);
            $registry->build();
            $this->mapperRegistry ??= $registry;
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
                // Lazy on purpose: this engine is memoized, and a dispatcher
                // captured here freezes whatever was resolvable at FIRST write —
                // in CLI workers that is before any bootstrap registered the
                // resolver, silently killing auto-publish for the whole process.
                fn (): ?EventDispatcherInterface => $this->getEventDispatcher(),
            );
        }

        return $this->aggregateWriteEngine;
    }

    /**
     * Register the lazy default EventDispatcher resolver (framework bootstrap only).
     * Invoked once per worker by ContainerFactory once the container can resolve
     * EventDispatcherInterface. Pass null to clear (tests).
     *
     * @param (\Closure(): ?EventDispatcherInterface)|null $resolver
     */
    public static function setDefaultEventDispatcherResolver(?\Closure $resolver): void
    {
        self::$defaultEventDispatcherResolver = $resolver;
    }

    /**
     * Resolve the EventDispatcher this manager dispatches resource-changed events
     * through: an explicitly injected one wins (P2's ctor param / direct tests),
     * otherwise the framework's lazy default resolver (the bootstrap-wired one),
     * otherwise null (no container bootstrapped → dispatch stays a silent no-op,
     * exactly as before this brick).
     */
    public function getEventDispatcher(): ?EventDispatcherInterface
    {
        if ($this->events !== null) {
            return $this->events;
        }

        if (self::$defaultEventDispatcherResolver !== null) {
            return (self::$defaultEventDispatcherResolver)();
        }

        return null;
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

    /**
     * Destructors run wherever GC happens to fire — mid-container-build, in
     * another coroutine burst's world, or with no coroutine at all — and a
     * Swoole Channel touched from the wrong context raises a C-level
     * "must call constructor first" fatal that BYPASSES try/catch (and,
     * inside a destructor, is uncatchable by any frame). So the destructor
     * only DROPS references: releasing the Channel lets refcounting free the
     * queued PDO connections exactly as a drain would, minus the fatal.
     * Deliberate teardown at a known-safe point stays {@see shutdown()}.
     */
    public function __destruct()
    {
        $this->pool = null;
        $this->adapter = null;
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

        if ($this->shouldUseCoroutinePool()) {
            return new ConnectionPool($poolSize, $factory);
        }

        return new SingleConnectionPool($factory);
    }

    /**
     * Whether the coroutine-safe ConnectionPool should back this manager.
     *
     * True under a running Swoole server, where PDO sockets are coroutine-hooked
     * (enableCoroutine(SWOOLE_HOOK_ALL) normally runs once in the master before
     * workers fork, so the flag is inherited at WorkerStart). getHookFlags() !== 0
     * is the causally-exact "server present" signal and is independent of the
     * current coroutine id. getCid() >= 0 is the in-coroutine fast-path. True CLI
     * (no server, hooks off, getCid() === -1) falls through to the single shared
     * connection, which is correct there.
     *
     * This is evaluated both at pool construction AND on every subsequent
     * getPool()/getAdapter() (see ensureCoroutineSafePool) so a SingleConnectionPool
     * cached before the runtime came up can still be upgraded once it is live.
     */
    private function shouldUseCoroutinePool(): bool
    {
        return class_exists(\Swoole\Coroutine::class, false)
            && (
                \Swoole\Coroutine::getCid() >= 0
                || (class_exists(\Swoole\Runtime::class, false)
                    && \Swoole\Runtime::getHookFlags() !== 0)
            );
    }

    /**
     * Self-heal a stale pool SELECTION.
     *
     * createPool() may cache the non-coroutine SingleConnectionPool if the very
     * first getPool()/getAdapter() ran before SWOOLE_HOOK_ALL was applied — e.g.
     * master-side warmup before fork, which then inherits the wrong pool into
     * every worker. That choice is otherwise frozen for the worker's life, and
     * SingleConnectionPool gives no true pooling under load. Once the coroutine
     * runtime is live, swap in the real ConnectionPool and drop every memoized
     * service that captured the old pool (directly or via the old adapter) so the
     * next access rebuilds against it.
     *
     * Never runs mid-transaction — yanking the pool out from under an open
     * transaction would orphan its connection.
     */
    private function ensureCoroutineSafePool(): void
    {
        if (! $this->pool instanceof SingleConnectionPool) {
            return;
        }

        if (! $this->shouldUseCoroutinePool()) {
            return;
        }

        if ($this->transactionManager !== null && $this->transactionManager->isActive()) {
            return;
        }

        $this->pool->close();
        $this->pool = $this->createPool();

        // Every field below captured the old pool, directly or via the old adapter.
        $this->adapter                      = null;
        $this->schemaComparator             = null;
        $this->syncEngine                   = null;
        $this->transactionManager           = null;
        $this->seedRunner                   = null;
        $this->resourceModelRelationLoader  = null;
        $this->aggregateWriteEngine         = null;
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

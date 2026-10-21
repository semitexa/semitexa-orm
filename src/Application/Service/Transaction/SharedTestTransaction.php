<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service\Transaction;

use Semitexa\Core\Attribute\WorkerState;
use Semitexa\Orm\Adapter\SqliteAdapter;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;

/**
 * A transaction a test opens on one connection and every OrmManager of the
 * process joins, so whatever the test and the code it calls write is undone by
 * one rollback.
 *
 * Why it has to be process-wide: application code does not share an
 * OrmManager. The container holds one per connection name, and about thirty
 * classes fall back to `new OrmManager()`; in a CLI process each of those owns a
 * SingleConnectionPool with a PDO of its own, so a transaction opened on one of
 * them is invisible to the rest and their writes autocommit. While a test
 * transaction is open for a connection name:
 *   - every connection pool serving that name hands out its connection and
 *     ignores it coming back;
 *   - every SqliteAdapter serving that name runs on it;
 *   - every TransactionManager of that name counts it as the outermost level, so
 *     run() nests inside it as a SAVEPOINT and never commits — which is also why
 *     the events a commit would flush never fire.
 *
 * A static, not a CoroutineLocal: an in-process request
 * (Application::handleRequest) wipes the CLI CoroutineLocal store on entry and
 * on exit, and the transaction has to outlive every request a test makes.
 *
 * Test-only. It refuses to start inside a coroutine: one PDO must never be
 * shared between coroutines. Hooks left on outside one do not matter — a
 * Co\run() leaves them on behind it unless they were set explicitly, so
 * OrmManager may have built a coroutine ConnectionPool for plain CLI code, and
 * that pool joins too: outside a coroutine every pool hands out this
 * connection, inside one it refuses.
 */
final class SharedTestTransaction
{
    /** @var array<string, self> Keyed by connection name. */
    #[WorkerState('Test-only: open between a test\'s setUp and tearDown; begin() refuses inside a coroutine, so a server never holds one.')]
    private static array $open = [];

    /** @var array<string, OrmManager> One manager per connection name, reused (a coroutine pool still opens a connection per test). */
    #[WorkerState('Test-only: the managers test transactions are opened on, keyed by connection name; never built in a server.')]
    private static array $managers = [];

    private function __construct(
        public readonly string $connectionName,
        public readonly string $driver,
        public readonly \PDO $connection,
        public readonly SingleConnectionAdapter $adapter,
        private readonly OrmManager $orm,
    ) {}

    /**
     * Open the test transaction for a connection name.
     *
     * @param ConnectionConfig|null $config the connection's settings; read from the environment,
     *                                      as ConnectionRegistry reads them, when null. Every
     *                                      manager of this name joins, whatever its own settings.
     */
    public static function begin(string $connectionName = 'default', ?ConnectionConfig $config = null): self
    {
        if (isset(self::$open[$connectionName])) {
            throw new \LogicException(sprintf(
                'A test transaction is already open on connection "%s". Roll it back before opening another.',
                $connectionName,
            ));
        }

        // Test code only: never in a long-running process that is not a test run.
        if (!class_exists(\PHPUnit\Framework\TestCase::class, false)) {
            throw new \LogicException('A test transaction opens only inside a PHPUnit run.');
        }

        if (class_exists(\Swoole\Coroutine::class, false) && \Swoole\Coroutine::getCid() >= 0) {
            throw new \LogicException(
                'A test transaction cannot run inside a Swoole coroutine: its one connection would be shared '
                . 'between coroutines. Open it outside Co\run(), or clean up with RefreshesTables instead.',
            );
        }

        // Reused only when the settings come from the environment: an explicit
        // config may name another database each time.
        $orm = $config !== null
            ? new OrmManager(config: $config, connectionName: $connectionName)
            : self::$managers[$connectionName] ??= new OrmManager(
                config: ConnectionConfig::fromEnvironment($connectionName),
                connectionName: $connectionName,
            );

        // An in-memory SQLite database is one per connection: this one would be
        // empty, and every manager of the name would be pointed at it.
        if ($orm->getDriver() === 'sqlite' && ($config ?? ConnectionConfig::fromEnvironment($connectionName))->sqliteMemory) {
            throw new \LogicException('A test transaction cannot share an in-memory SQLite database: use a SQLite file, or MySQL.');
        }

        $adapter = $orm->getAdapter();
        // Before taking the connection: on a cold MySQL adapter this runs a
        // detection query on a connection of its own (see TransactionManager::runOuter).
        $serverVersion = $adapter->getServerVersion();

        if ($adapter instanceof SqliteAdapter) {
            $pool = null;
            $pdo = $adapter->getPdo();
        } else {
            $pool = $orm->getPool();
            $pdo = $pool->pop();
        }

        try {
            $pdo->beginTransaction();
        } catch (\Throwable $e) {
            $pool?->push($pdo);
            throw $e;
        }

        return self::$open[$connectionName] = new self(
            $connectionName,
            $pool === null ? 'sqlite' : 'mysql',
            $pdo,
            new SingleConnectionAdapter($pdo, $serverVersion, $connectionName),
            $orm,
        );
    }

    /** The open test transaction of this connection name and driver, or null. */
    public static function for(string $connectionName, string $driver): ?self
    {
        $open = self::$open[$connectionName] ?? null;

        return $open !== null && $open->driver === $driver ? $open : null;
    }

    /** The connection a test transaction lends to every pool of this name, or null. */
    public static function lentConnection(?string $connectionName): ?\PDO
    {
        return $connectionName === null ? null : self::for($connectionName, 'mysql')?->connection;
    }

    /**
     * For a pool's pop(): outside a coroutine, the test's connection; inside
     * one, a refusal — two coroutines on one hooked PDO is an uncatchable
     * fatal, and a connection of their own would commit. A coroutine
     * ConnectionPool serves plain CLI code too, since Co\run() leaves the hooks
     * on behind it unless they were set explicitly.
     */
    public static function lendToCoroutinePool(?string $connectionName, bool $inCoroutine): ?\PDO
    {
        $lent = self::lentConnection($connectionName);
        if ($lent !== null && $inCoroutine) {
            throw new \LogicException(sprintf(
                'A coroutine asked for a connection while a test transaction is open on "%s": one PDO must '
                . 'never be shared between coroutines, and a connection of its own would commit writes the '
                . 'rollback cannot undo. Clean up with RefreshesTables in a test that runs coroutines.',
                (string) $connectionName,
            ));
        }

        return $lent;
    }

    /**
     * Roll the transaction back and stop sharing its connection.
     *
     * @throws \LogicException when the transaction was no longer open: something
     *                         committed it — on MySQL any DDL or TRUNCATE does so
     *                         implicitly — and what was written after that stays
     */
    public function rollBack(): void
    {
        if ((self::$open[$this->connectionName] ?? null) === $this) {
            unset(self::$open[$this->connectionName]);
        }

        // Buffered for a commit that will never come; CLI keeps them process-wide.
        $this->orm->getTransactionManager()->clearPendingEvents();

        $stillOpen = false;
        try {
            $stillOpen = $this->connection->inTransaction();
            if ($stillOpen) {
                $this->connection->rollBack();
            }
        } finally {
            if ($this->driver === 'mysql') {
                $this->orm->getPool()->push($this->connection);
            }
        }

        if (!$stillOpen) {
            throw new \LogicException(sprintf(
                'The test transaction on connection "%s" was committed before the test ended, so its writes '
                . 'were NOT rolled back. On MySQL every DDL statement (CREATE, ALTER, DROP) and TRUNCATE '
                . 'commits implicitly. Create schema before the test (setUpBeforeClass), and empty tables '
                . 'with RefreshesTables instead of TRUNCATE.',
                $this->connectionName,
            ));
        }
    }
}

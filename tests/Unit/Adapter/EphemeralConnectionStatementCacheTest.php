<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\ConnectionPool;
use Semitexa\Orm\Adapter\ConnectionPoolInterface;
use Semitexa\Orm\Adapter\EphemeralConnectionAwareInterface;
use Semitexa\Orm\Adapter\MysqlAdapter;
use Semitexa\Orm\Adapter\SingleConnectionPool;
use Swoole\Coroutine;

/**
 * Caching a prepared statement for an EPHEMERAL connection (minted for one
 * caller, dropped on push) creates a WeakMap→PDOStatement→PDO cycle that only
 * cycle-GC reclaims — query-dense CLI stretches then hold every socket open
 * until the next sweep (observed as MySQL max_connections exhaustion). The
 * old guard asked the POOL TYPE ("does this pool hand out ephemerals?") and
 * only knew ConnectionPool; SingleConnectionPool's crash-avoidance mints
 * leaked. The guard now asks about the CONNECTION itself, on any pool that
 * implements EphemeralConnectionAwareInterface.
 */
final class EphemeralConnectionStatementCacheTest extends TestCase
{
    #[Test]
    public function single_connection_pool_marks_only_foreign_connections_ephemeral(): void
    {
        $pool = new SingleConnectionPool(static fn (): \PDO => new \PDO('sqlite::memory:'));

        $cached = $pool->pop();
        self::assertFalse($pool->isEphemeralConnection($cached), 'the cached connection comes back on push — cacheable');
        self::assertTrue(
            $pool->isEphemeralConnection(new \PDO('sqlite::memory:')),
            'a crash-avoidance mint never returns to the cache — must not be statement-cached',
        );
    }

    #[Test]
    public function connection_pool_marks_slotless_connections_ephemeral(): void
    {
        if (!class_exists(\Swoole\Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }

        Coroutine\run(function () {
            $pool = new ConnectionPool(1, static fn (): \PDO => new \PDO('sqlite::memory:'));

            $pooled = $pool->pop();
            self::assertFalse($pool->isEphemeralConnection($pooled), 'a slot-holding connection is pooled');
            self::assertTrue(
                $pool->isEphemeralConnection(new \PDO('sqlite::memory:')),
                'a connection that claimed no slot is ephemeral',
            );
            $pool->push($pooled);
            $pool->close();
        });
    }

    #[Test]
    public function the_adapter_skips_the_statement_cache_for_ephemeral_connections(): void
    {
        $ephemeralPdo = new PrepareCountingPdo();
        $adapter = new MysqlAdapter(new ForcedEphemeralPool($ephemeralPdo, ephemeral: true));

        $adapter->execute('SELECT 1 AS a WHERE 1 = :v', ['v' => 1]);
        $adapter->execute('SELECT 1 AS a WHERE 1 = :v', ['v' => 1]);
        self::assertSame(2, $ephemeralPdo->prepareCalls, 'an ephemeral connection must be re-prepared every time (no cache entry pinning it)');

        $pooledPdo = new PrepareCountingPdo();
        $adapter = new MysqlAdapter(new ForcedEphemeralPool($pooledPdo, ephemeral: false));

        $adapter->execute('SELECT 1 AS a WHERE 1 = :v', ['v' => 1]);
        $adapter->execute('SELECT 1 AS a WHERE 1 = :v', ['v' => 1]);
        self::assertSame(1, $pooledPdo->prepareCalls, 'a pooled connection reuses its cached statement');
    }
}

/** Always hands out the same PDO; reports it ephemeral or pooled on demand. */
final class ForcedEphemeralPool implements ConnectionPoolInterface, EphemeralConnectionAwareInterface
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly bool $ephemeral,
    ) {}

    public function isEphemeralConnection(\PDO $connection): bool
    {
        return $this->ephemeral;
    }

    public function pop(?float $timeout = null): \PDO { return $this->pdo; }
    public function push(\PDO $connection): void {}
    public function close(): void {}
    public function getSize(): int { return 1; }
    public function getAvailable(): int { return 1; }
    public function switchTo(string $tenantId): void {}
}

/** A sqlite PDO that counts prepare() calls. */
final class PrepareCountingPdo extends \PDO
{
    public int $prepareCalls = 0;

    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        $this->prepareCalls++;

        return parent::prepare($query, $options);
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\ConnectionPoolInterface;
use Semitexa\Orm\Adapter\MysqlAdapter;
use Semitexa\Orm\Application\Service\Transaction\SingleConnectionAdapter;

/**
 * The adapters cache prepared statements per connection: the pool runs
 * native prepares (ATTR_EMULATE_PREPARES=false), so a repeated templated
 * SQL string must prepare ONCE per connection, not once per execute.
 * Proven with a PDO subclass that counts prepare() calls over a real
 * in-memory SQLite database.
 */
final class StatementCacheTest extends TestCase
{
    #[Test]
    public function mysql_adapter_prepares_a_repeated_statement_once_per_connection(): void
    {
        $pdo = new CountingPdo('sqlite::memory:');
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
        $adapter = new MysqlAdapter(new FixedConnectionPool($pdo));

        $adapter->execute('INSERT INTO t (v) VALUES (:v)', ['v' => 'a']);
        $adapter->execute('INSERT INTO t (v) VALUES (:v)', ['v' => 'b']);
        $adapter->execute('INSERT INTO t (v) VALUES (:v)', ['v' => 'c']);

        self::assertSame(1, $pdo->prepareCalls, 'One prepare must serve every execute of the same SQL.');
        self::assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM t')->fetchColumn());
    }

    #[Test]
    public function single_connection_adapter_prepares_a_repeated_statement_once(): void
    {
        $pdo = new CountingPdo('sqlite::memory:');
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
        $adapter = new SingleConnectionAdapter($pdo, '8.0');

        $adapter->execute('INSERT INTO t (v) VALUES (:v)', ['v' => 'a']);
        $adapter->execute('INSERT INTO t (v) VALUES (:v)', ['v' => 'b']);

        self::assertSame(1, $pdo->prepareCalls);
        self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM t')->fetchColumn());
    }

    #[Test]
    public function distinct_sql_strings_prepare_separately(): void
    {
        $pdo = new CountingPdo('sqlite::memory:');
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
        $adapter = new MysqlAdapter(new FixedConnectionPool($pdo));

        $adapter->execute('INSERT INTO t (v) VALUES (:v)', ['v' => 'a']);
        $adapter->execute('SELECT v FROM t WHERE v = :v', ['v' => 'a']);

        self::assertSame(2, $pdo->prepareCalls);
    }
}

final class CountingPdo extends \PDO
{
    public int $prepareCalls = 0;

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        $this->prepareCalls++;

        return parent::prepare($query, $options);
    }
}

/** A "pool" that always hands out the same connection — enough for cache tests. */
final class FixedConnectionPool implements ConnectionPoolInterface
{
    public function __construct(private readonly \PDO $pdo) {}

    public function pop(float $timeout = -1): \PDO
    {
        return $this->pdo;
    }

    public function push(\PDO $connection): void
    {
    }

    public function close(): void
    {
    }

    public function getSize(): int
    {
        return 1;
    }

    public function getAvailable(): int
    {
        return 1;
    }

    public function switchTo(string $database): void
    {
    }
}

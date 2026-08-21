<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Sync;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\ConnectionPoolInterface;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\QueryResult;
use Semitexa\Orm\Adapter\ServerCapability;
use Semitexa\Orm\Application\Service\Sync\SyncEngine;
use Semitexa\Orm\Domain\Enum\DdlOperationType;
use Semitexa\Orm\Domain\Model\DdlOperation;
use Semitexa\Orm\Domain\Model\ExecutionPlan;

/**
 * A DDL plan must run on ONE connection. Through a pooled adapter every
 * query()/execute() pops a DIFFERENT connection, so BEGIN, each DDL and
 * COMMIT would land on unrelated connections — and the connection that
 * received BEGIN goes back to the pool with an open transaction that an
 * unrelated coroutine then inherits. This pins the dedicated-connection
 * path: with a pool present, the whole plan executes on a single popped
 * PDO and the pooled adapter is never touched.
 */
final class SyncEngineSingleConnectionTest extends TestCase
{
    #[Test]
    public function the_whole_plan_runs_on_one_dedicated_connection(): void
    {
        // Hands out a FRESH PDO per pop, like a real pool under concurrency:
        // if each operation popped its own connection, the INSERT would not
        // see the table the CREATE made and the plan would fail.
        $pool = new FreshPdoPerPopPool();
        $outerAdapter = new RecordingNonTransactionalAdapter();
        $engine = new SyncEngine($outerAdapter, null, $pool);

        $plan = new ExecutionPlan();
        $plan->addOperation(new DdlOperation(
            sql: 'CREATE TABLE sync_probe (id INTEGER PRIMARY KEY)',
            type: DdlOperationType::CreateTable,
            tableName: 'sync_probe',
            isDestructive: false,
            description: 'create probe table',
        ));
        $plan->addOperation(new DdlOperation(
            sql: 'INSERT INTO sync_probe (id) VALUES (1)',
            type: DdlOperationType::CreateTable,
            tableName: 'sync_probe',
            isDestructive: false,
            description: 'insert into the table the previous operation created',
        ));

        $executed = $engine->execute($plan);

        self::assertCount(2, $executed, 'both operations must execute successfully');
        self::assertSame(1, $pool->popCount, 'the whole plan must borrow exactly one connection');
        self::assertSame(1, $pool->pushCount, 'the borrowed connection must go back to the pool');
        self::assertSame(
            [],
            $outerAdapter->statements,
            'the pooled adapter must never execute plan operations (each call would use a different connection)',
        );
        self::assertFalse(
            $pool->pushed[0]->inTransaction(),
            'the connection must return to the pool without an open transaction',
        );
    }

    #[Test]
    public function a_mysql_plan_never_opens_a_transaction_on_the_pooled_connection(): void
    {
        // MySQL performs an implicit commit around every DDL statement, so a
        // START TRANSACTION/COMMIT wrapper there is a fiction: the first DDL
        // already committed, and the closing COMMIT has nothing to commit
        // (PDO answers "There is no active transaction"). Worse, between the
        // BEGIN and the first DDL the pooled connection carries an untracked
        // open transaction that push() cannot see. Pin the honest behavior:
        // no transaction control statements on the MySQL path at all.
        $pool = new FreshPdoPerPopPool();
        $engine = new SyncEngine(new AtomicDdlCapableAdapter(), null, $pool);

        $plan = new ExecutionPlan();
        $plan->addOperation(new DdlOperation(
            sql: 'CREATE TABLE mysql_path_probe (id INTEGER PRIMARY KEY)',
            type: DdlOperationType::CreateTable,
            tableName: 'mysql_path_probe',
            isDestructive: false,
            description: 'create probe table',
        ));

        $executed = $engine->execute($plan);

        self::assertCount(1, $executed);
        self::assertFalse(
            $pool->pushed[0]->inTransaction(),
            'the connection must go back to the pool with no transaction of any kind',
        );
    }

    #[Test]
    public function a_failing_plan_still_returns_the_connection_to_the_pool(): void
    {
        $pool = new FreshPdoPerPopPool();
        $engine = new SyncEngine(new RecordingNonTransactionalAdapter(), null, $pool);

        $plan = new ExecutionPlan();
        $plan->addOperation(new DdlOperation(
            sql: 'THIS IS NOT SQL',
            type: DdlOperationType::CreateTable,
            tableName: 'broken',
            isDestructive: false,
            description: 'invalid statement',
        ));

        try {
            $engine->execute($plan);
            self::fail('the invalid statement must propagate');
        } catch (\Throwable) {
            // expected
        }

        self::assertSame(1, $pool->popCount);
        self::assertSame(1, $pool->pushCount, 'the connection must be returned even when the plan fails');
        self::assertFalse($pool->pushed[0]->inTransaction());
    }
}

/** Hands out a fresh real sqlite PDO on every pop and records the traffic. */
final class FreshPdoPerPopPool implements ConnectionPoolInterface
{
    public int $popCount = 0;
    public int $pushCount = 0;

    /** @var \PDO[] */
    public array $pushed = [];

    public function pop(?float $timeout = null): \PDO
    {
        $this->popCount++;

        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    public function push(\PDO $connection): void
    {
        $this->pushCount++;
        $this->pushed[] = $connection;
    }

    public function close(): void {}
    public function getSize(): int { return 1; }
    public function getAvailable(): int { return 1; }
    public function switchTo(string $tenantId): void {}
}

/**
 * The outer (pooled) adapter: non-SQLite so the engine takes the MySQL path,
 * AtomicDdl unsupported so no BEGIN/COMMIT wraps the sqlite probe statements.
 * Records every statement — the dedicated-connection path must leave it empty.
 */
final class RecordingNonTransactionalAdapter implements DatabaseAdapterInterface
{
    /** @var string[] */
    public array $statements = [];

    public function supports(ServerCapability $capability): bool { return false; }
    public function getServerVersion(): string { return '8.0.0'; }

    public function execute(string $sql, array $params = []): QueryResult
    {
        $this->statements[] = $sql;

        return new QueryResult();
    }

    public function query(string $sql): QueryResult
    {
        $this->statements[] = $sql;

        return new QueryResult();
    }

    public function lastInsertId(): string { return '0'; }
}

/**
 * A non-SQLite adapter that DOES advertise AtomicDdl (MySQL 8.0+ shape).
 * The engine must still issue no transaction control on this path.
 */
final class AtomicDdlCapableAdapter implements DatabaseAdapterInterface
{
    /** @var string[] */
    public array $statements = [];

    public function supports(ServerCapability $capability): bool { return true; }
    public function getServerVersion(): string { return '8.0.35'; }

    public function execute(string $sql, array $params = []): QueryResult
    {
        $this->statements[] = $sql;

        return new QueryResult();
    }

    public function query(string $sql): QueryResult
    {
        $this->statements[] = $sql;

        return new QueryResult();
    }

    public function lastInsertId(): string { return '0'; }
}

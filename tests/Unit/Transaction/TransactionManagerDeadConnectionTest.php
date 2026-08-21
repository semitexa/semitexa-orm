<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Transaction;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\ConnectionPoolInterface;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\QueryResult;
use Semitexa\Orm\Adapter\ServerCapability;
use Semitexa\Orm\Application\Service\Transaction\TransactionManager;

/**
 * A connection that dies mid-transaction makes BOTH commit() and rollBack()
 * throw. The exception worth seeing is the ORIGINAL commit failure — it names
 * the real cause; a rollback error thrown from inside the catch used to
 * replace it, sending the caller chasing the wrong exception. The state must
 * also fully reset so the next transaction starts clean.
 */
final class TransactionManagerDeadConnectionTest extends TestCase
{
    #[Test]
    public function a_failing_rollback_does_not_mask_the_original_commit_failure(): void
    {
        $pool = new DeadCommitPool();
        $manager = new TransactionManager($pool, new DeadConnFakeAdapter());

        try {
            $manager->run(static fn () => 'unreachable');
            self::fail('the commit failure must propagate');
        } catch (\PDOException $e) {
            self::assertSame(
                'MySQL server has gone away at COMMIT',
                $e->getMessage(),
                'the ORIGINAL commit failure must surface, not the secondary rollback error',
            );
        }

        self::assertFalse($manager->isActive(), 'the transaction state must fully reset after the dead connection');
        self::assertSame([], $manager->getPendingEvents());
        self::assertSame(1, $pool->pushCount, 'the dead connection must still be handed back to the pool for hygiene/discard');

        // The manager remains usable: the next transaction pops a fresh
        // connection and takes the OUTER branch (depth was reset).
        $result = $manager->run(static fn () => 'clean-after-death');
        self::assertSame('clean-after-death', $result);
    }
}

/** First pop: a PDO dead at COMMIT time (commit AND rollback throw). Later pops: healthy sqlite. */
final class DeadCommitPool implements ConnectionPoolInterface
{
    public int $popCount = 0;
    public int $pushCount = 0;

    public function pop(?float $timeout = null): \PDO
    {
        $this->popCount++;

        return $this->popCount === 1 ? new DeadAtCommitPdo() : new \PDO('sqlite::memory:');
    }

    public function push(\PDO $connection): void
    {
        $this->pushCount++;
    }

    public function close(): void {}
    public function getSize(): int { return 2; }
    public function getAvailable(): int { return 2; }
    public function switchTo(string $tenantId): void {}
}

/** Accepts BEGIN, then dies: commit() and rollBack() both throw. */
final class DeadAtCommitPdo extends \PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
    }

    public function commit(): bool
    {
        throw new \PDOException('MySQL server has gone away at COMMIT');
    }

    public function inTransaction(): bool
    {
        return true;
    }

    public function rollBack(): bool
    {
        throw new \PDOException('rollback on a dead connection also fails');
    }
}

/** Non-SQLite adapter so run() takes the pooled path. */
final class DeadConnFakeAdapter implements DatabaseAdapterInterface
{
    public function supports(ServerCapability $capability): bool { return true; }
    public function getServerVersion(): string { return '8.0.0'; }
    public function execute(string $sql, array $params = []): QueryResult { return new QueryResult(); }
    public function query(string $sql): QueryResult { return new QueryResult(); }
    public function lastInsertId(): string { return '0'; }
}

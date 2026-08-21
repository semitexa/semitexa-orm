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
use Semitexa\Orm\Exception\DeadlockException;

/**
 * runWithRetry() replays the WHOLE transaction on deadlock/lock-wait/
 * connection-lost — the only correct retry unit (the server already rolled
 * the victim transaction back; replaying one statement of it would apply a
 * partial write). Nested calls are never retried: only the outermost caller
 * can meaningfully replay.
 */
final class TransactionManagerRetryTest extends TestCase
{
    #[Test]
    public function a_deadlocked_transaction_is_replayed_from_the_top_and_succeeds(): void
    {
        $pool = new RetryTestPool();
        $manager = new TransactionManager($pool, new RetryTestAdapter());

        $bodyRuns = 0;
        $result = $manager->runWithRetry(function () use (&$bodyRuns) {
            $bodyRuns++;
            if ($bodyRuns < 3) {
                throw new DeadlockException('Deadlock found when trying to get lock', '40001', 1213);
            }

            return 'committed-on-attempt-3';
        }, attempts: 3);

        self::assertSame('committed-on-attempt-3', $result);
        self::assertSame(3, $bodyRuns, 'the whole transaction body must re-run from the top');
        self::assertSame(3, $pool->popCount, 'each replay runs on its own freshly popped connection');
        self::assertFalse($manager->isActive(), 'no transaction state may linger after the retries');
    }

    #[Test]
    public function the_exception_propagates_once_attempts_are_exhausted(): void
    {
        $pool = new RetryTestPool();
        $manager = new TransactionManager($pool, new RetryTestAdapter());

        $bodyRuns = 0;

        try {
            $manager->runWithRetry(function () use (&$bodyRuns) {
                $bodyRuns++;
                throw new DeadlockException('Deadlock found', '40001', 1213);
            }, attempts: 2);
            self::fail('the deadlock must propagate after the attempts are spent');
        } catch (DeadlockException) {
            // expected
        }

        self::assertSame(2, $bodyRuns);
    }

    #[Test]
    public function a_nested_transaction_is_never_retried(): void
    {
        $pool = new RetryTestPool();
        $manager = new TransactionManager($pool, new RetryTestAdapter());

        $innerRuns = 0;

        try {
            $manager->run(function () use ($manager, &$innerRuns) {
                // Outer transaction open; the nested runWithRetry must NOT
                // replay — the server rolled back the OUTER transaction, so
                // only the outer caller can meaningfully retry.
                return $manager->runWithRetry(function () use (&$innerRuns) {
                    $innerRuns++;
                    throw new DeadlockException('Deadlock found', '40001', 1213);
                }, attempts: 5);
            });
            self::fail('the deadlock must propagate through the nested call');
        } catch (DeadlockException) {
            // expected
        }

        self::assertSame(1, $innerRuns, 'a nested transaction must fail upward, not replay in place');
    }

    #[Test]
    public function a_non_transient_failure_is_not_retried(): void
    {
        $pool = new RetryTestPool();
        $manager = new TransactionManager($pool, new RetryTestAdapter());

        $bodyRuns = 0;

        try {
            $manager->runWithRetry(function () use (&$bodyRuns) {
                $bodyRuns++;
                throw new \RuntimeException('domain failure');
            }, attempts: 3);
            self::fail('the domain failure must propagate immediately');
        } catch (\RuntimeException $e) {
            self::assertSame('domain failure', $e->getMessage());
        }

        self::assertSame(1, $bodyRuns, 'only transient transactional failures qualify for replay');
    }
}

/** Hands out a fresh sqlite PDO per pop and counts checkouts. */
final class RetryTestPool implements ConnectionPoolInterface
{
    public int $popCount = 0;

    public function pop(?float $timeout = null): \PDO
    {
        $this->popCount++;

        return new \PDO('sqlite::memory:');
    }

    public function push(\PDO $connection): void {}
    public function close(): void {}
    public function getSize(): int { return 3; }
    public function getAvailable(): int { return 3; }
    public function switchTo(string $tenantId): void {}
}

/** A non-SQLite adapter so run() takes the pooled outer-transaction path. */
final class RetryTestAdapter implements DatabaseAdapterInterface
{
    public function supports(ServerCapability $capability): bool { return true; }
    public function getServerVersion(): string { return '8.0.0'; }
    public function execute(string $sql, array $params = []): QueryResult { return new QueryResult(); }
    public function query(string $sql): QueryResult { return new QueryResult(); }
    public function lastInsertId(): string { return '0'; }
}

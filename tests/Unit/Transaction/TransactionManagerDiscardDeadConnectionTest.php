<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Transaction;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\ConnectionPool;
use Semitexa\Orm\Application\Service\Transaction\TransactionManager;
use Semitexa\Orm\Tests\Fixture\Transaction\DeadConnFakeAdapter;

/**
 * A pooled connection that dies while idle fails at BEGIN with 2006. The
 * pool's idle ping only runs for connections idle >= 1s, so pushing the dead
 * connection back made runWithRetry()'s replay (5-45ms later) pop the SAME
 * dead socket on every attempt and fail the whole call. runOuter() must
 * discard a connection that failed with ConnectionLostException.
 */
final class TransactionManagerDiscardDeadConnectionTest extends TestCase
{
    #[Test]
    public function run_with_retry_does_not_replay_on_the_same_dead_connection(): void
    {
        if (! class_exists(\Swoole\Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }

        $minted = 0;
        $pool = new ConnectionPool(2, static function () use (&$minted): \PDO {
            $minted++;

            return $minted === 1 ? new DeadAtBeginPdo() : new \PDO('sqlite::memory:');
        });
        $manager = new TransactionManager($pool, new DeadConnFakeAdapter());

        $result = null;
        $error = null;
        \Swoole\Coroutine\run(static function () use ($manager, &$result, &$error): void {
            try {
                $result = $manager->runWithRetry(static fn () => 'committed');
            } catch (\Throwable $e) {
                $error = $e;
            }
        });

        self::assertNull($error, 'retry must reach a healthy connection: ' . ($error?->getMessage() ?? ''));
        self::assertSame('committed', $result);
        self::assertSame(2, $minted);
        self::assertSame(1, $pool->getStats()['discards']);
        self::assertSame(1, $pool->getStats()['created'], 'the dead connection must give its slot back');
    }
}

final class DeadAtBeginPdo extends \PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
    }

    public function beginTransaction(): bool
    {
        $e = new \PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        $e->errorInfo = ['HY000', 2006, 'MySQL server has gone away'];
        throw $e;
    }
}

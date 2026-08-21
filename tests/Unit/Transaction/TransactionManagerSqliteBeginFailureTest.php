<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Transaction;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\NullConnectionPool;
use Semitexa\Orm\Adapter\SqliteAdapter;
use Semitexa\Orm\Application\Service\Transaction\TransactionManager;

/**
 * On the SQLite path beginTransaction() used to run OUTSIDE the try/finally.
 * A failure there skipped the cleanup, leaving depth=1 and an active
 * connection behind — so the NEXT run() on the same coroutine took the
 * nested-savepoint branch against a transaction that was never opened, and
 * every later transaction on that coroutine was corrupt.
 */
final class TransactionManagerSqliteBeginFailureTest extends TestCase
{
    #[Test]
    public function a_failing_begin_leaves_no_transaction_state_behind(): void
    {
        $adapter = new BeginRefusingSqliteAdapter();
        $manager = new TransactionManager(new NullConnectionPool(), $adapter);

        $callbackRan = false;

        try {
            $manager->run(static function () use (&$callbackRan) {
                $callbackRan = true;

                return null;
            });
            self::fail('the BEGIN failure must propagate');
        } catch (\Throwable $e) {
            self::assertStringContainsString('cannot start a transaction', $e->getMessage());
        }

        self::assertFalse($callbackRan, 'the callback must not run when the transaction never opened');
        self::assertFalse($manager->isActive(), 'depth must be reset so the next run() takes the OUTER branch');
        self::assertSame([], $manager->getPendingEvents());
        self::assertNull($manager->currentAdapter(), 'no transaction adapter may linger');
    }
}

/** A SQLite adapter whose connection refuses to open a transaction. */
final class BeginRefusingSqliteAdapter extends SqliteAdapter
{
    private ?\PDO $refusing = null;

    public function __construct()
    {
        parent::__construct('sqlite::memory:');
    }

    public function getPdo(): \PDO
    {
        return $this->refusing ??= new BeginRefusingPdo();
    }

    public function getServerVersion(): string
    {
        return '3.45.0';
    }
}

/** A PDO that fails to begin a transaction, like a locked/unusable database file. */
final class BeginRefusingPdo extends \PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
    }

    public function beginTransaction(): bool
    {
        throw new \PDOException('SQLSTATE[HY000]: General error: 5 cannot start a transaction within a transaction');
    }
}

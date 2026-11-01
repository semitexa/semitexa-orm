<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Integration\Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Tests\Fixture\Query\BookedNightResourceModel;
use Semitexa\Orm\Tests\Support\RequiresDatabase;

/**
 * On MySQL, forUpdate() holds the rows it read until the transaction ends:
 * another session asking for the same row with NOWAIT is turned away while
 * the transaction is open, and is not without forUpdate() (orm-gap-3).
 */
final class ForUpdateLockTest extends TestCase
{
    use RequiresDatabase;

    private OrmManager $orm;
    private OrmManager $otherNode;
    private \PDO $otherSession;

    protected function setUp(): void
    {
        $this->orm = self::mysqlOrm('row locks are MySQL\'s');
        $this->orm->getAdapter()->execute('CREATE TABLE IF NOT EXISTS orm_test_booked_nights (id VARCHAR(36) PRIMARY KEY, room VARCHAR(32) NOT NULL, night VARCHAR(10) NOT NULL) ENGINE=InnoDB');
        $this->orm->getAdapter()->execute('DELETE FROM orm_test_booked_nights');
        $this->orm->getAdapter()->execute("INSERT INTO orm_test_booked_nights VALUES ('n1', 'blue', '2026-11-07')");
        $this->otherNode = new OrmManager();
        $this->otherSession = $this->otherNode->getPool()->pop();
    }

    protected function tearDown(): void
    {
        if (isset($this->otherSession)) {
            $this->otherNode->getPool()->push($this->otherSession);
            $this->otherNode->shutdown();
        }
        if (isset($this->orm)) {
            try {
                $this->orm->getAdapter()->execute('DROP TABLE IF EXISTS orm_test_booked_nights');
            } finally {
                $this->orm->shutdown();
            }
        }
    }

    #[Test]
    public function the_rows_a_locking_read_returned_stay_locked_until_the_transaction_ends(): void
    {
        $query = fn () => $this->orm->query(BookedNightResourceModel::class)->where(BookedNightResourceModel::column('room'), Operator::Equals, 'blue');
        self::assertStringEndsWith(' FOR UPDATE', $query()->forUpdate()->toSql());

        $whileLocked = $this->orm->getTransactionManager()->run(function () use ($query): string {
            self::assertCount(1, $query()->forUpdate()->fetchAll());

            return $this->otherAsksForTheRow();
        });
        self::assertSame('refused: 3572', $whileLocked, 'another session cannot take the row the transaction locked');
        self::assertSame('got it', $this->otherAsksForTheRow(), 'the lock ended with the transaction');

        $plainRead = $this->orm->getTransactionManager()->run(function () use ($query): string {
            self::assertCount(1, $query()->fetchAll());

            return $this->otherAsksForTheRow();
        });
        self::assertSame('got it', $plainRead, 'a plain read inside a transaction locks nothing');
    }

    /** The other session's SELECT … FOR UPDATE NOWAIT on the same row: 3572 is ER_LOCK_NOWAIT. */
    private function otherAsksForTheRow(): string
    {
        $this->otherSession->beginTransaction();
        try {
            $this->otherSession->query("SELECT id FROM orm_test_booked_nights WHERE id = 'n1' FOR UPDATE NOWAIT")->fetchAll();

            return 'got it';
        } catch (\PDOException $e) {
            return 'refused: ' . ($e->errorInfo[1] ?? $e->getCode());
        } finally {
            $this->otherSession->rollBack();
        }
    }
}

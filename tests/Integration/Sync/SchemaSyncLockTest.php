<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Integration\Sync;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Application\Service\Sync\SchemaSyncLock;
use Semitexa\Orm\Exception\SchemaSyncLockTimeoutException;
use Semitexa\Orm\OrmManager;

/**
 * Two nodes deploying at once must not both diff, plan and run the same DDL.
 * A second OrmManager is a second session — the other node.
 */
final class SchemaSyncLockTest extends TestCase
{
    private OrmManager $orm;
    private OrmManager $otherNode;
    /** The other node's session, held for the whole test like SchemaSyncLock holds its own. */
    private \PDO $otherSession;
    private string $lockName;

    protected function setUp(): void
    {
        try {
            $this->orm = new OrmManager();
            if ($this->orm->getDriver() !== 'mysql') {
                throw new \RuntimeException('the schema lock is MySQL-only');
            }
            $this->orm->getAdapter()->query('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('MySQL unavailable: ' . $e->getMessage());
        }

        // A named lock belongs to a session. Going through the adapter would
        // return the connection to the pool after each statement — under a
        // coroutine pool that may close it, and the lock with it.
        $this->otherNode    = new OrmManager();
        $this->otherSession = $this->otherNode->getPool()->pop();
        $this->lockName     = SchemaSyncLock::lockName($this->orm->getDatabaseName());
    }

    protected function tearDown(): void
    {
        if (isset($this->otherSession)) {
            $this->otherSql('SELECT RELEASE_LOCK(:n)');
            $this->otherNode->getPool()->push($this->otherSession);
            $this->otherNode->shutdown();
        }
        if (isset($this->orm)) {
            $this->orm->shutdown();
        }
    }

    #[Test]
    public function a_sync_waits_for_the_node_holding_the_lock_and_gives_up_without_running(): void
    {
        self::assertSame(1, (int) $this->otherSql('SELECT GET_LOCK(:n, 0)'));

        $ran = false;
        try {
            (new SchemaSyncLock($this->orm, waitSeconds: 1))->run(function () use (&$ran): void {
                $ran = true;
            });
            self::fail('a sync must not proceed while another node holds the schema lock');
        } catch (SchemaSyncLockTimeoutException $e) {
            self::assertStringContainsString($this->lockName, $e->getMessage());
        }

        self::assertFalse($ran, 'the cycle must not run without the lock');
    }

    #[Test]
    public function the_lock_is_released_after_the_cycle_even_when_it_throws(): void
    {
        $lock = new SchemaSyncLock($this->orm, waitSeconds: 1);

        self::assertSame('applied', $lock->run(fn (): string => 'applied'));
        self::assertSame(1, $this->isFree());

        try {
            $lock->run(static function (): never {
                throw new \RuntimeException('DDL failed');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame(1, $this->isFree(), 'a failed sync must not leave the schema locked');
    }

    private function isFree(): int
    {
        return (int) $this->otherSql('SELECT IS_FREE_LOCK(:n)');
    }

    private function otherSql(string $sql): mixed
    {
        $statement = $this->otherSession->prepare($sql);
        $statement->execute(['n' => $this->lockName]);

        return $statement->fetchColumn();
    }
}

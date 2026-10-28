<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Transaction;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;

/** A file stored for a row that was rolled back stayed, referenced by nothing. */
final class TransactionAfterRollbackTest extends TestCase
{
    #[Test]
    public function work_for_a_rollback_runs_on_a_rollback_only_and_for_its_own_savepoint(): void
    {
        $tm = (new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true)))->getTransactionManager();
        $ran = [];

        $tm->run(function () use ($tm, &$ran): void {
            $tm->afterRollback(static function () use (&$ran): void { $ran[] = 'committed'; });
        });
        self::assertSame([], $ran, 'dropped on commit');

        try {
            $tm->run(function () use ($tm, &$ran): void {
                $tm->afterRollback(static function () use (&$ran): void { $ran[] = 'outer'; });
                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
        }
        self::assertSame(['outer'], $ran);

        $ran = [];
        $tm->run(function () use ($tm, &$ran): void {
            $tm->afterRollback(static function () use (&$ran): void { $ran[] = 'kept'; });
            try {
                $tm->run(function () use ($tm, &$ran): void {
                    $tm->afterRollback(static function () use (&$ran): void { $ran[] = 'savepoint'; });
                    throw new \RuntimeException('savepoint rolled back');
                });
            } catch (\RuntimeException) {
            }
        });
        self::assertSame(['savepoint'], $ran, 'its savepoint rolled back; the outer one committed');

        $tm->afterRollback(static function () use (&$ran): void { $ran[] = 'none open'; });
        self::assertSame(['savepoint'], $ran, 'nothing open: nothing to roll back');
    }
}

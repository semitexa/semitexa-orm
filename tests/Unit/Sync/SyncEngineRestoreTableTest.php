<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Sync;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Application\Service\Sync\SyncEngine;
use Semitexa\Orm\Domain\Model\SchemaDiff;

/**
 * A table marked SEMITEXA_DEPRECATED (phase 1 of a two-phase drop) that is
 * back in code kept the mark: the next sync that missed the table, for one
 * run, dropped it with no warning phase. Found on a dev database where 56 of
 * 58 live tables carried the mark.
 */
final class SyncEngineRestoreTableTest extends TestCase
{
    #[Test]
    public function a_table_back_in_code_has_its_deprecation_mark_cleared_safely(): void
    {
        $diff = new SchemaDiff();
        $diff->addRestoreTable('platform_user');

        $operations = (new SyncEngine($this->createMock(DatabaseAdapterInterface::class)))->buildPlan($diff)->getOperations();

        self::assertCount(1, $operations);
        self::assertSame("ALTER TABLE `platform_user` COMMENT ''", $operations[0]->sql);
        self::assertFalse($operations[0]->isDestructive, 'runs without --allow-destructive');
        self::assertFalse($diff->isEmpty());
    }
}

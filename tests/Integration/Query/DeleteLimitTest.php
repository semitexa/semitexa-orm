<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Integration\Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\DeleteQuery;
use Semitexa\Orm\Tests\Support\RequiresDatabase;

/** On MySQL a limited delete is DELETE ... LIMIT, and reports what it took (orm-gap-4-bulk-writes). */
final class DeleteLimitTest extends TestCase
{
    use RequiresDatabase;

    private OrmManager $orm;

    protected function setUp(): void
    {
        $this->orm = self::mysqlOrm('DELETE ... LIMIT is MySQL\'s');
        $this->orm->getAdapter()->execute('CREATE TABLE IF NOT EXISTS orm_test_purge (id INT PRIMARY KEY, kept TINYINT NOT NULL) ENGINE=InnoDB');
        $this->orm->getAdapter()->execute('DELETE FROM orm_test_purge');
        $this->orm->getAdapter()->execute('INSERT INTO orm_test_purge VALUES (1, 0), (2, 0), (3, 0), (4, 0), (5, 1)');
    }

    protected function tearDown(): void
    {
        if (isset($this->orm)) {
            try {
                $this->orm->getAdapter()->execute('DROP TABLE IF EXISTS orm_test_purge');
            } finally {
                $this->orm->shutdown();
            }
        }
    }

    #[Test]
    public function a_retention_purge_runs_in_batches_and_leaves_what_it_did_not_match(): void
    {
        $batches = [];
        do {
            $deleted = (new DeleteQuery('orm_test_purge', $this->orm->getQueryAdapter()))->where('kept', '=', 0)->limit(3)->executeWhere();
            $batches[] = $deleted;
        } while ($deleted === 3);

        self::assertSame([3, 1], $batches);
        self::assertSame([['id' => 5]], array_map(static fn (array $r): array => ['id' => (int) $r['id']], $this->orm->getAdapter()->query('SELECT id FROM orm_test_purge')->rows));
    }
}

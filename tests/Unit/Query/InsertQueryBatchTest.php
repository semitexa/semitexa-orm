<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\InsertQuery;

/**
 * executeBatch() folds many rows into a single multi-row INSERT with a distinct
 * placeholder set per row (`:col_0`, `:col_1`, … — native-prepare safe). It is
 * what AggregateWriteEngine now uses to sync pivot rows in one round-trip
 * instead of one INSERT per related item; exercised here against a real
 * in-memory SQLite driver so the generated SQL is proven to execute and land.
 */
final class InsertQueryBatchTest extends TestCase
{
    private DatabaseAdapterInterface $adapter;

    protected function setUp(): void
    {
        $this->adapter = (new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true)))->getAdapter();
        $this->adapter->execute('CREATE TABLE pivot (a TEXT NOT NULL, b TEXT NOT NULL)');
    }

    #[Test]
    public function execute_batch_inserts_every_row_in_one_statement(): void
    {
        (new InsertQuery('pivot', $this->adapter))->executeBatch([
            ['a' => 'x', 'b' => '1'],
            ['a' => 'y', 'b' => '2'],
            ['a' => 'z', 'b' => '3'],
        ]);

        $rows = $this->adapter->query('SELECT a, b FROM pivot ORDER BY a')->rows;
        self::assertSame([
            ['a' => 'x', 'b' => '1'],
            ['a' => 'y', 'b' => '2'],
            ['a' => 'z', 'b' => '3'],
        ], $rows);
    }

    #[Test]
    public function execute_batch_rejects_an_empty_row_set(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new InsertQuery('pivot', $this->adapter))->executeBatch([]);
    }
}

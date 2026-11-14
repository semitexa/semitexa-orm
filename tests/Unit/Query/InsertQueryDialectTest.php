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
 * The upsert form is the dialect's, asked through any wrapper (tk-orm-dialect-layer, step 3).
 * Inside a transaction the adapter is a SingleConnectionAdapter, which `instanceof SqliteAdapter`
 * never recognised: SQLite was sent MySQL's ON DUPLICATE KEY there and failed with a syntax error.
 */
final class InsertQueryDialectTest extends TestCase
{
    #[Test]
    public function an_upsert_inside_a_transaction_on_sqlite_is_written_in_sqlites_form(): void
    {
        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $orm->getAdapter()->execute('CREATE TABLE dialect_kv (k TEXT PRIMARY KEY, v TEXT)');

        $orm->getTransactionManager()->run(static function (DatabaseAdapterInterface $adapter): void {
            (new InsertQuery('dialect_kv', $adapter))->execute(['k' => 'a', 'v' => 'first'], upsert: true);
            (new InsertQuery('dialect_kv', $adapter))->execute(['k' => 'a', 'v' => 'second'], upsert: true);
        });

        self::assertSame([['k' => 'a', 'v' => 'second']], $orm->getAdapter()->query('SELECT k, v FROM dialect_kv')->rows);
    }
}

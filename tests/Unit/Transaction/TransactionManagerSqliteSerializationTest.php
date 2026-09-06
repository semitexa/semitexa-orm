<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Transaction;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\NullConnectionPool;
use Semitexa\Orm\Adapter\SqliteAdapter;
use Semitexa\Orm\Application\Service\Transaction\TransactionManager;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;

/**
 * SqliteAdapter owns ONE PDO for the whole worker, while the transaction state
 * (depth, active connection) is coroutine-local. So two coroutines each see
 * depth 0, each take the OUTER branch, and both call beginTransaction() on the
 * SAME PDO: the second either throws "There is already an active transaction"
 * or — worse — the first coroutine's commit ends the second's transaction too,
 * and a read-modify-write is silently lost.
 *
 * The pooled MySQL path cannot hit this (each outer transaction pops its own
 * connection). SQLite has no pool to pop from, so the serialisation has to be
 * explicit: one coroutine's BEGIN..COMMIT completes before the next starts.
 */
final class TransactionManagerSqliteSerializationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }
    }

    #[Test]
    public function concurrent_outer_transactions_serialize_on_the_shared_connection(): void
    {
        $errors = [];
        $final = null;

        Coroutine\run(function () use (&$errors, &$final): void {
            $adapter = new SqliteAdapter('sqlite::memory:');
            $adapter->execute('CREATE TABLE seq (id INTEGER PRIMARY KEY, n INTEGER NOT NULL)');
            $adapter->execute('INSERT INTO seq (id, n) VALUES (1, 0)');

            $manager = new TransactionManager(new NullConnectionPool(), $adapter);

            // Read the counter, YIELD mid-transaction — the exact point another
            // coroutine can begin or commit on the shared PDO — then write n+1.
            $work = static function () use ($manager, &$errors): void {
                try {
                    $manager->run(static function (DatabaseAdapterInterface $tx): void {
                        $n = (int) $tx->query('SELECT n FROM seq WHERE id = 1')->fetchColumn();
                        Coroutine::sleep(0.01);
                        $tx->execute('UPDATE seq SET n = :n WHERE id = 1', ['n' => $n + 1]);
                    });
                } catch (\Throwable $e) {
                    $errors[] = $e->getMessage();
                }
            };

            $wg = new WaitGroup();
            for ($i = 0; $i < 5; $i++) {
                $wg->add();
                Coroutine::create(static function () use ($work, $wg): void {
                    $work();
                    $wg->done();
                });
            }
            $wg->wait();

            $final = (int) $adapter->query('SELECT n FROM seq WHERE id = 1')->fetchColumn();
        });

        self::assertSame([], $errors, 'no coroutine may collide on the shared SQLite connection');
        self::assertSame(5, $final, 'every read-modify-write applied — none lost to interleaving');
    }
}

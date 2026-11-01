<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Transaction;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Tests\Support\RequiresDatabase;

/**
 * Raw SQL in package stores went through getAdapter(), which under Swoole's
 * pool takes a connection of its own and autocommits even inside a caller's
 * transaction: nothing rolled back. (In CLI the pool is one shared
 * connection, so the escape does not show here.) getQueryAdapter() joins the
 * transaction.
 */
final class QueryAdapterJoinsTransactionTest extends TestCase
{
    use RequiresDatabase;

    #[Test]
    public function a_raw_write_through_the_query_adapter_rolls_back_with_the_transaction(): void
    {
        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $orm->getQueryAdapter()->execute('CREATE TABLE qa_probe (id INTEGER PRIMARY KEY, v TEXT)');

        $this->rollBackAWrite($orm, 'qa_probe');

        self::assertSame([], $orm->getQueryAdapter()->execute('SELECT v FROM qa_probe')->rows);
    }

    #[Test]
    public function on_mysql_a_raw_write_through_the_query_adapter_rolls_back_too(): void
    {
        $orm = self::mysqlOrm('the test is about InnoDB');

        // Dropped only once set up: a setup that failed on a table already
        // there must not take that table with it.
        $setUp = false;
        try {
            $orm->getAdapter()->execute('CREATE TABLE IF NOT EXISTS qa_probe_tx (id INT AUTO_INCREMENT PRIMARY KEY, v VARCHAR(16)) ENGINE=InnoDB');
            $orm->getAdapter()->execute('DELETE FROM qa_probe_tx');
            $setUp = true;
            $this->rollBackAWrite($orm, 'qa_probe_tx');
            self::assertSame([], $orm->getAdapter()->execute('SELECT v FROM qa_probe_tx')->rows, 'the joined write rolled back');

        } finally {
            try {
                if ($setUp) {
                    $orm->getAdapter()->execute('DROP TABLE IF EXISTS qa_probe_tx');
                }
            } finally {
                $orm->shutdown();
            }
        }
    }

    private function rollBackAWrite(OrmManager $orm, string $table): void
    {
        try {
            $orm->getTransactionManager()->run(function () use ($orm, $table): void {
                $orm->getQueryAdapter()->execute("INSERT INTO {$table} (v) VALUES ('inside')");
                // The transaction sees its own write.
                self::assertCount(1, $orm->getQueryAdapter()->execute("SELECT v FROM {$table}")->rows);
                throw new \RuntimeException('roll back');
            });
        } catch (\RuntimeException) {
        }
    }

    #[Test]
    public function under_a_coroutine_pool_the_query_adapter_joins_and_get_adapter_escapes(): void
    {
        // The case that matters: a real pool, where getAdapter() takes a
        // second connection. Without the change both writes would survive.
        // Skipped or failed out here; inside the coroutine every error fails.
        self::mysqlOrm('the test is about a MySQL pool')->shutdown();
        $outcome = null;
        $failure = null;
        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        try {
            \Swoole\Coroutine\run(function () use (&$outcome, &$failure): void {
                $orm = new OrmManager();
                $setUp = false;
                try {
                    $orm->getQueryAdapter()->execute('CREATE TABLE IF NOT EXISTS qa_probe_pool (id INT AUTO_INCREMENT PRIMARY KEY, v VARCHAR(16)) ENGINE=InnoDB');
                    $orm->getQueryAdapter()->execute('DELETE FROM qa_probe_pool');
                    $setUp = true;
                    try {
                        $orm->getTransactionManager()->run(static function () use ($orm): void {
                            $orm->getQueryAdapter()->execute("INSERT INTO qa_probe_pool (v) VALUES ('joined')");
                            $orm->getAdapter()->execute("INSERT INTO qa_probe_pool (v) VALUES ('escaped')");
                            throw new \RuntimeException('roll back');
                        });
                    } catch (\RuntimeException) {
                    }
                    $outcome = array_column($orm->getQueryAdapter()->execute('SELECT v FROM qa_probe_pool')->rows, 'v');
                } catch (\Throwable $e) {
                    $failure = $e;
                } finally {
                    // The first error is the one the test reports: a cleanup
                    // that fails after it must not replace it, nor escape the
                    // coroutine.
                    try {
                        if ($setUp) {
                            $orm->getQueryAdapter()->execute('DROP TABLE IF EXISTS qa_probe_pool');
                        }
                    } catch (\Throwable $e) {
                        $failure ??= $e;
                    } finally {
                        $orm->shutdown();
                    }
                }
            });
        } finally {
            // Hooks off outside a coroutine, and every Co\run() hooking again, as it
            // does after any Co\run(). A bare enableCoroutine(0) left later Co\run() calls with
            // no hooks at all: the next test talking to a socket inside one blocked
            // the whole process (a NATS test hung the full suite at 45%).
            \Swoole\Runtime::enableCoroutine(0);
            \Swoole\Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL]);
        }

        if ($failure !== null) {
            throw $failure;
        }
        self::assertSame(['escaped'], $outcome, 'the joined write rolled back; the pooled one did not');
    }
}

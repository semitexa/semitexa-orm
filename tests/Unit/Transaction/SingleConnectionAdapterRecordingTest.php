<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Transaction;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\QueryRecorder;
use Semitexa\Orm\Application\Service\Transaction\SingleConnectionAdapter;

/**
 * Queries executed INSIDE a transaction run through SingleConnectionAdapter,
 * which used to bypass QueryRecorder entirely — every in-transaction write
 * was invisible to traces and profiles, the exact path one inspects when
 * debugging a slow or deadlocking write.
 */
final class SingleConnectionAdapterRecordingTest extends TestCase
{
    protected function tearDown(): void
    {
        QueryRecorder::stop();
    }

    #[Test]
    public function in_transaction_queries_are_visible_to_the_query_recorder(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $adapter = new SingleConnectionAdapter($pdo, '8.0.0');

        QueryRecorder::start();

        $adapter->query('CREATE TABLE rec_probe (id INTEGER PRIMARY KEY)');
        $adapter->execute('INSERT INTO rec_probe (id) VALUES (:id)', ['id' => 1]);

        $log = QueryRecorder::drain();

        self::assertCount(2, $log, 'both the raw query and the prepared execute must be recorded');
        self::assertSame('CREATE TABLE rec_probe (id INTEGER PRIMARY KEY)', $log[0]['sql']);
        self::assertSame('INSERT INTO rec_probe (id) VALUES (:id)', $log[1]['sql']);
        self::assertSame(['id' => 1], $log[1]['params']);
        self::assertGreaterThanOrEqual(0.0, $log[1]['timeMs']);
    }

    #[Test]
    public function nothing_is_recorded_when_the_recorder_is_off(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $adapter = new SingleConnectionAdapter($pdo, '8.0.0');

        $adapter->query('SELECT 1');

        self::assertSame([], QueryRecorder::drain());
    }
}

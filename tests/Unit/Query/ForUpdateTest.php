<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\SqliteAdapter;
use Semitexa\Orm\Application\Service\Hydration\ResourceModelHydrator;
use Semitexa\Orm\Application\Service\Hydration\ResourceModelRelationLoader;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Query\ResourceModelQuery;
use Semitexa\Orm\Tests\Fixture\Query\BookedNightResourceModel;

/**
 * forUpdate() is a locking read only where it can lock anything: inside a
 * transaction, on the transaction-aware adapter. Elsewhere it is refused,
 * not run as a plain read (orm-gap-3-locking-read: a probe on a pooled
 * connection outside the BEGIN once sold the same nights twice).
 */
final class ForUpdateTest extends TestCase
{
    private OrmManager $orm;

    protected function setUp(): void
    {
        $this->orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $this->orm->getQueryAdapter()->execute('CREATE TABLE orm_test_booked_nights (id VARCHAR(36) PRIMARY KEY, room VARCHAR(32) NOT NULL, night VARCHAR(10) NOT NULL)');
        $this->orm->getQueryAdapter()->execute("INSERT INTO orm_test_booked_nights VALUES ('n1', 'blue', '2026-11-07')");
    }

    protected function tearDown(): void
    {
        $this->orm->shutdown();
    }

    #[Test]
    public function inside_a_transaction_it_reads_what_it_locks(): void
    {
        $found = $this->orm->getTransactionManager()->run(fn (): array => [
            $this->nights()->forUpdate()->fetchAll(),
            $this->nights()->forUpdate()->exists(),
            $this->nights()->forUpdate()->fetchOne(),
        ]);

        self::assertSame(['n1'], array_map(static fn (object $n): string => $n->id, $found[0]));
        self::assertTrue($found[1]);
        self::assertSame('2026-11-07', $found[2]?->night);
        // SQLite has no row locks: no clause, the transaction serializes writers.
        self::assertStringNotContainsString('FOR UPDATE', $this->nights()->forUpdate()->toSql());
        self::assertSame("SELECT * FROM `orm_test_booked_nights` WHERE `room` = :w0", $this->nights()->forUpdate()->toSql());
    }

    #[Test]
    public function outside_a_transaction_it_is_refused_not_run_unlocked(): void
    {
        $message = 'forUpdate() outside TransactionManager::run(): the lock would end with the statement\'s own autocommit and protect nothing. Run the read and the write it guards in one transaction.';
        foreach (['fetchAll', 'fetchOne', 'exists'] as $method) {
            try {
                $this->nights()->forUpdate()->{$method}();
                self::fail("{$method}() ran outside a transaction");
            } catch (\LogicException $e) {
                self::assertSame($message, $e->getMessage(), $method);
            }
        }
        // Without forUpdate() the same read runs anywhere.
        self::assertCount(1, $this->nights()->fetchAll());
    }

    #[Test]
    public function on_a_pooled_adapter_it_is_refused_even_in_a_transaction(): void
    {
        $pooled = new SqliteAdapter('sqlite::memory:');
        $hydrator = new ResourceModelHydrator();
        $query = new ResourceModelQuery(BookedNightResourceModel::class, $pooled, $hydrator, new ResourceModelRelationLoader($pooled, $hydrator));

        $this->expectExceptionMessage('forUpdate() needs the transaction-aware adapter (a repository, OrmManager::query() or getQueryAdapter()): on a pooled adapter the read runs outside the transaction and its lock ends at once.');
        $this->orm->getTransactionManager()->run(static fn (): array => $query->forUpdate()->fetchAll());
    }

    #[Test]
    public function an_aggregate_or_a_page_of_a_locking_query_is_refused(): void
    {
        $refused = [
            'count()' => fn () => $this->nights()->forUpdate()->count(),
            'sum()' => fn () => $this->nights()->forUpdate()->sum(BookedNightResourceModel::column('night')),
            'countBy()' => fn () => $this->nights()->forUpdate()->countBy(BookedNightResourceModel::column('room')),
            'count() (paginate)' => fn () => $this->nights()->forUpdate()->paginate(1, 10),
        ];
        foreach ($refused as $method => $call) {
            try {
                $this->orm->getTransactionManager()->run($call);
                self::fail("{$method} ran on a locking query");
            } catch (\LogicException $e) {
                self::assertSame(str_replace(' (paginate)', '', $method) . ' of a forUpdate() query: a locking read is fetch*() or exists().', $e->getMessage());
            }
        }
    }

    private function nights(): ResourceModelQuery
    {
        return $this->orm->query(BookedNightResourceModel::class)->where(BookedNightResourceModel::column('room'), Operator::Equals, 'blue');
    }
}

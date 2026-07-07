<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\Metadata\ColumnRef;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\ResourceModelQuery;

/**
 * Single-table SQL aggregations on the query builder: sum/avg/min/max +
 * countBy grouping — every reporting query that used to force raw SQL.
 * They compose with the SAME WHERE state as fetchAll(), proven on real
 * SQLite including the filtered paths.
 */
final class QueryAggregationTest extends TestCase
{
    private OrmManager $orm;

    protected function setUp(): void
    {
        $this->orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $adapter = $this->orm->getAdapter();
        $adapter->execute('CREATE TABLE agg_orders (id TEXT PRIMARY KEY, status TEXT, amount INTEGER)');
        $adapter->execute(
            "INSERT INTO agg_orders VALUES ('a', 'open', 10), ('b', 'open', 30), ('c', 'done', 5), ('d', 'done', 15), ('e', 'canceled', 100)"
        );
    }

    private function query(): ResourceModelQuery
    {
        return new ResourceModelQuery(
            AggOrderFixture::class,
            $this->orm->getAdapter(),
            $this->orm->getResourceModelHydrator(),
            $this->orm->getResourceModelRelationLoader(),
        );
    }

    #[Test]
    public function aggregates_cover_the_whole_table_without_filters(): void
    {
        self::assertSame(160, $this->query()->sum(self::amount()));
        self::assertSame(32.0, $this->query()->avg(self::amount()));
        self::assertSame(5, (int) $this->query()->min(self::amount()));
        self::assertSame(100, (int) $this->query()->max(self::amount()));
    }

    #[Test]
    public function aggregates_respect_the_where_state(): void
    {
        $openSum = $this->query()
            ->where(self::statusColumn(), Operator::Equals, 'open')
            ->sum(self::amount());

        self::assertSame(40, $openSum);
    }

    #[Test]
    public function empty_sets_return_zero_sum_and_null_avg_min_max(): void
    {
        $none = fn (): ResourceModelQuery => $this->query()->where(self::statusColumn(), Operator::Equals, 'missing');

        self::assertSame(0, $none()->sum(self::amount()));
        self::assertNull($none()->avg(self::amount()));
        self::assertNull($none()->min(self::amount()));
        self::assertNull($none()->max(self::amount()));
    }

    #[Test]
    public function count_by_groups_and_orders_by_frequency(): void
    {
        $counts = $this->query()->countBy(self::statusColumn());

        self::assertSame(['open' => 2, 'done' => 2, 'canceled' => 1], $counts);
    }

    #[Test]
    public function count_by_respects_the_where_state(): void
    {
        $counts = $this->query()
            ->where(self::amount(), Operator::GreaterThan, 10)
            ->countBy(self::statusColumn());

        self::assertSame(['open' => 1, 'done' => 1, 'canceled' => 1], $counts);
    }

    #[Test]
    public function a_foreign_column_ref_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->query()->sum(ColumnRef::for(ForeignAggFixture::class, 'amount'));
    }

    private static function amount(): ColumnRef
    {
        return ColumnRef::for(AggOrderFixture::class, 'amount');
    }

    private static function statusColumn(): ColumnRef
    {
        return ColumnRef::for(AggOrderFixture::class, 'status');
    }
}

#[FromTable(name: 'agg_orders')]
final readonly class AggOrderFixture
{
    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,

        #[Column(type: MySqlType::Varchar, length: 32)]
        public string $status,

        #[Column(type: MySqlType::Int)]
        public int $amount,
    ) {}
}

#[FromTable(name: 'agg_foreign')]
final readonly class ForeignAggFixture
{
    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,

        #[Column(type: MySqlType::Int)]
        public int $amount,
    ) {}
}

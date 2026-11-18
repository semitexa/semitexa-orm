<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\Metadata\ColumnRef;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Direction;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Query\ResourceModelQuery;
use Semitexa\Orm\Tests\Fixtures\Query\OtherTableFixture;
use Semitexa\Orm\Tests\Fixtures\Query\RankedLeadFixture;

/**
 * orm-gap-5-expression-order-group: "un-worked first, then newest" (ORDER BY
 * CASE), GROUP BY with SUM/MAX, and SELECT DISTINCT, which a store wrote as raw
 * SQL because the query builder could not express them. On real SQLite.
 */
final class OrderByValuesAndAggregateTest extends TestCase
{
    private OrmManager $orm;

    protected function setUp(): void
    {
        $this->orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $adapter = $this->orm->getAdapter();
        $adapter->execute('CREATE TABLE ranked_leads (id TEXT PRIMARY KEY, status TEXT NULL, region TEXT, amount INTEGER, created INTEGER)');
        $adapter->execute(
            "INSERT INTO ranked_leads VALUES ('a', 'won', 'north', 10, 1), ('b', 'new', 'south', 30, 2), ('c', 'contacted', 'north', 5, 3),"
            . " ('d', 'called', 'south', 15, 4), ('e', 'new', 'north', 100, 5), ('f', NULL, 'east', 7, 6)"
        );
    }

    #[Test]
    public function rows_follow_the_listed_values_then_the_tie_break_and_unlisted_ones_come_last(): void
    {
        $rows = $this->query()
            ->orderByValues(self::col('status'), ['new', ['contacted', 'called']], Direction::Asc)
            ->orderBy(self::col('created'), Direction::Desc)
            ->fetchAll();

        // new (newest first), then contacted and called sharing a place, then won and NULL, unlisted.
        self::assertSame(['e', 'b', 'd', 'c', 'f', 'a'], self::ids($rows));
    }

    #[Test]
    public function desc_puts_the_unlisted_rows_first_and_the_first_place_last(): void
    {
        $rows = $this->query()
            ->orderByValues(self::col('status'), ['new'], Direction::Desc)
            ->orderBy(self::col('created'), Direction::Asc)
            ->fetchAll();

        self::assertSame(['a', 'c', 'd', 'f', 'b', 'e'], self::ids($rows));
    }

    /** Fable 17.11: count(), exists() and paginate's count leave the ORDER BY out, its bindings with it. */
    #[Test]
    public function it_binds_beside_a_where_and_count_exists_and_paginate_leave_it_out(): void
    {
        $query = fn (): ResourceModelQuery => $this->query()
            ->where(self::col('region'), Operator::Equals, 'north')
            ->orderByValues(self::col('status'), ['new', 'won'], Direction::Asc);

        self::assertSame(['e', 'a', 'c'], self::ids($query()->fetchAll()));
        self::assertSame(3, $query()->count());
        self::assertTrue($query()->exists());

        $page = $query()->paginate(1, 2);
        self::assertSame([3, ['e', 'a']], [$page->total, self::ids($page->items)]);
    }

    /** @return iterable<string, array{array<mixed>, string}> */
    public static function refusedValues(): iterable
    {
        yield 'no value' => [[], 'orderByValues() needs at least one value to order by.'];
        yield 'NULL, which CASE never matches' => [['new', null], 'orderByValues() cannot rank NULL (CASE never matches it); order nulls with orderBy() or leave them unlisted, they come last.'];
        yield 'an empty place' => [['new', []], 'orderByValues(): a place is an empty list; give it at least one value.'];
        yield 'a deeper list' => [[['new', ['won']]], 'orderByValues(): a place is a value or a list of values, not a deeper list.'];
    }

    /** @param array<mixed> $values */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedValues')]
    public function a_list_it_cannot_rank_is_refused(array $values, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->query()->orderByValues(self::col('status'), $values, Direction::Asc);
    }

    #[Test]
    public function sum_and_max_per_group_come_biggest_first(): void
    {
        $aggregate = $this->query()->aggregate();

        self::assertSame(['north' => 115, 'south' => 45, 'east' => 7], $aggregate->sumBy(self::col('region'), self::col('amount')));
        self::assertSame(['north' => 100, 'south' => 30, 'east' => 7], array_map(static fn (mixed $max): int => is_numeric($max) ? (int) $max : -1, $aggregate->maxBy(self::col('region'), self::col('amount'))));
    }

    /** Review, orm#122: a group of NULL values sums to 0 before the order, so its tie with a real 0 breaks by group. */
    #[Test]
    public function a_group_of_nulls_sums_to_zero_and_ties_by_group(): void
    {
        $this->orm->getAdapter()->execute("INSERT INTO ranked_leads VALUES ('g', 'won', 'zzz', 0, 7), ('h', 'won', 'aaa', NULL, 8)");

        self::assertSame(
            ['north' => 115, 'south' => 45, 'east' => 7, 'aaa' => 0, 'zzz' => 0],
            $this->query()->aggregate()->sumBy(self::col('region'), self::col('amount')),
        );
    }

    #[Test]
    public function distinct_values_come_ascending_with_null_last(): void
    {
        self::assertSame(['called', 'contacted', 'new', 'won', null], $this->query()->aggregate()->distinctValues(self::col('status')));
    }

    #[Test]
    public function an_aggregate_reads_the_where_but_not_the_order_or_the_limit(): void
    {
        $aggregate = $this->query()
            ->where(self::col('region'), Operator::Equals, 'north')
            ->orderByValues(self::col('status'), ['won'], Direction::Asc)
            ->limit(1)
            ->aggregate();

        self::assertSame(['contacted', 'new', 'won'], $aggregate->distinctValues(self::col('status')));
        self::assertSame(['north' => 115], $aggregate->sumBy(self::col('region'), self::col('amount')));
    }

    #[Test]
    public function a_locking_read_has_no_aggregate(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('aggregate() of a forUpdate() query');

        $this->query()->forUpdate()->aggregate();
    }

    #[Test]
    public function a_column_of_another_table_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ColumnRef for ' . OtherTableFixture::class . ' cannot be used in an aggregate of ' . RankedLeadFixture::class . '.');

        $this->query()->aggregate()->sumBy(self::col('region'), ColumnRef::for(OtherTableFixture::class, 'amount'));
    }

    private function query(): ResourceModelQuery
    {
        return new ResourceModelQuery(
            RankedLeadFixture::class,
            $this->orm->getAdapter(),
            $this->orm->getResourceModelHydrator(),
            $this->orm->getResourceModelRelationLoader(),
        );
    }

    private static function col(string $property): ColumnRef
    {
        return ColumnRef::for(RankedLeadFixture::class, $property);
    }

    /**
     * @param list<object> $rows
     * @return list<string>
     */
    private static function ids(array $rows): array
    {
        return array_map(static fn (object $row): string => $row instanceof RankedLeadFixture ? $row->id : '?', $rows);
    }
}

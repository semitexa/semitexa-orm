<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Application\Service\Hydration\ResourceModelHydrator;
use Semitexa\Orm\Application\Service\Hydration\ResourceModelRelationLoader;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Query\ResourceModelQuery;
use Semitexa\Orm\Tests\Fixture\Collection\CollectionFakeAdapter;
use Semitexa\Orm\Tests\Fixture\Collection\CollectionPingResourceModel;

/**
 * One Way Phase 2: the single OR-group capability
 * ({@see ResourceModelQuery::whereAnyLike()}) — SQL shape, parameter
 * binding, composition with sibling AND predicates, and the
 * regression guarantee that queries NOT using the group keep their
 * exact pre-Phase-2 assembly (param naming included).
 */
final class WhereAnyLikeGroupTest extends TestCase
{
    private function query(): ResourceModelQuery
    {
        $adapter = new CollectionFakeAdapter();
        $hydrator = new ResourceModelHydrator();

        return new ResourceModelQuery(
            CollectionPingResourceModel::class,
            $adapter,
            $hydrator,
            new ResourceModelRelationLoader($adapter, $hydrator),
        );
    }

    #[Test]
    public function renders_a_parenthesized_or_group_of_likes(): void
    {
        $query = $this->query()->whereAnyLike(
            [
                CollectionPingResourceModel::column('label'),
                CollectionPingResourceModel::column('body'),
            ],
            '%ping%',
        );

        $this->assertSame(
            'SELECT * FROM `collection_pings` WHERE (`label` LIKE :g0 OR `body` LIKE :g1)',
            $query->toSql(),
        );
        $this->assertSame(['g0' => '%ping%', 'g1' => '%ping%'], $query->toParams());
    }

    #[Test]
    public function group_joins_siblings_with_and(): void
    {
        $query = $this->query()
            ->where(CollectionPingResourceModel::column('label'), Operator::Equals, 'x')
            ->whereAnyLike(
                [
                    CollectionPingResourceModel::column('label'),
                    CollectionPingResourceModel::column('body'),
                ],
                '%q%',
            );

        $this->assertSame(
            'SELECT * FROM `collection_pings` WHERE `label` = :w0 AND (`label` LIKE :g1 OR `body` LIKE :g2)',
            $query->toSql(),
        );
    }

    #[Test]
    public function single_member_group_still_parenthesizes(): void
    {
        $query = $this->query()->whereAnyLike(
            [CollectionPingResourceModel::column('label')],
            '%q%',
        );

        $this->assertSame(
            'SELECT * FROM `collection_pings` WHERE (`label` LIKE :g0)',
            $query->toSql(),
        );
    }

    #[Test]
    public function empty_column_list_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->query()->whereAnyLike([], '%q%');
    }

    #[Test]
    public function foreign_column_ref_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->query()->whereAnyLike(
            [\Semitexa\Orm\Tests\Fixture\Hydration\HydratableProductResourceModel::column('name')],
            '%q%',
        );
    }

    #[Test]
    public function non_grouped_assembly_is_unchanged_by_the_extension(): void
    {
        // Regression pin (§3 discipline): a representative non-grouped
        // chain must assemble exactly as before the group kind existed —
        // same fragments, same connectors, same param names.
        $query = $this->query()
            ->where(CollectionPingResourceModel::column('label'), Operator::Like, '%a%')
            ->whereIn(CollectionPingResourceModel::column('id'), ['p1', 'p2'])
            ->orderBy(CollectionPingResourceModel::column('created_at'), \Semitexa\Orm\Query\Direction::Desc)
            ->limit(5)
            ->offset(10);

        $this->assertSame(
            'SELECT * FROM `collection_pings` WHERE `label` LIKE :w0 AND `id` IN (:in1, :in2) '
            . 'ORDER BY `created_at` DESC LIMIT 5 OFFSET 10',
            $query->toSql(),
        );
        $this->assertSame(
            ['w0' => '%a%', 'in1' => 'p1', 'in2' => 'p2'],
            $query->toParams(),
        );
    }
}

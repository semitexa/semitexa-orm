<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\CollectionCriteria;
use Semitexa\Core\Resource\CollectionPaginationPolicy;
use Semitexa\Core\Resource\Cursor\CollectionCursor;
use Semitexa\Core\Resource\Cursor\CollectionCursorCodec;
use Semitexa\Core\Resource\Exception\InvalidCursorException;
use Semitexa\Core\Resource\Exception\InvalidPaginationException;
use Semitexa\Core\Resource\Filter\CollectionFilterRequest;
use Semitexa\Core\Resource\Pagination\CollectionPageRequest;
use Semitexa\Core\Resource\Sort\CollectionSortRequest;
use Semitexa\Orm\Application\Service\Hydration\ResourceModelHydrator;
use Semitexa\Orm\Application\Service\Hydration\ResourceModelRelationLoader;
use Semitexa\Orm\Query\CollectionQueryCompiler;
use Semitexa\Orm\Query\ResourceModelQuery;
use Semitexa\Orm\Tests\Fixture\Collection\CollectionFakeAdapter;
use Semitexa\Orm\Tests\Fixture\Collection\CollectionPingResourceModel;

/**
 * One Way Phase 2: the ORM criteria compiler — push-down SQL shape
 * (WHERE incl. the search OR-group, ORDER BY with the id tie-breaker,
 * LIMIT/OFFSET), the auto-mode flip at countThreshold, cursor
 * windowing + keyset continuation, and the typed-400 postures.
 */
final class CollectionQueryCompilerTest extends TestCase
{
    private const FIELD_MAP = [
        'id'        => 'id',
        'label'     => 'label',
        'body'      => 'body',
        'createdAt' => 'created_at',
    ];

    private const SORT_ALLOW = ['createdAt', 'label'];
    private const FILTER_ALLOW = ['label' => ['eq', 'contains'], 'id' => ['eq', 'in']];

    /** @param list<array<string, mixed>> $rows */
    private function queryOver(CollectionFakeAdapter $adapter): ResourceModelQuery
    {
        $hydrator = new ResourceModelHydrator();

        return new ResourceModelQuery(
            CollectionPingResourceModel::class,
            $adapter,
            $hydrator,
            new ResourceModelRelationLoader($adapter, $hydrator),
        );
    }

    /** @return list<array<string, mixed>> */
    private static function rows(int $n, string $stamp = '2026-06-01 10:00:00'): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = [
                'id'         => 'p' . $i,
                'label'      => 'ping ' . $i,
                'body'       => 'body ' . $i,
                'created_at' => $stamp,
            ];
        }

        return $out;
    }

    private function criteria(
        ?string $q = null,
        string $sort = '',
        string $filter = '',
        string $page = '',
        string $perPage = '',
        ?string $cursor = null,
        ?CollectionPaginationPolicy $policy = null,
    ): CollectionCriteria {
        $policy ??= CollectionPaginationPolicy::default();

        return new CollectionCriteria(
            page: CollectionPageRequest::fromQueryParams(
                $page === '' ? null : $page,
                $perPage === '' ? null : $perPage,
                $policy->defaultPerPage,
                $policy->maxPerPage,
            ),
            sort:             CollectionSortRequest::fromQueryParam($sort === '' ? null : $sort, self::SORT_ALLOW),
            filter:           CollectionFilterRequest::fromQueryParam($filter === '' ? null : $filter, self::FILTER_ALLOW),
            q:                $q,
            searchFields:     ['label', 'body'],
            cursor:           $cursor,
            policy:           $policy,
            pageWasRequested: $page !== '',
        );
    }

    private static function autoPolicy(int $threshold = 10): CollectionPaginationPolicy
    {
        return new CollectionPaginationPolicy(
            mode:           CollectionPaginationPolicy::MODE_AUTO,
            defaultPerPage: 5,
            perPageOptions: [5, 10, 25],
            maxPerPage:     25,
            countThreshold: $threshold,
        );
    }

    #[Test]
    public function page_mode_pushes_filter_search_sort_and_window_down_to_sql(): void
    {
        $adapter = new CollectionFakeAdapter(total: 7, rows: self::rows(3));
        $compiled = (new CollectionQueryCompiler())->compile(
            $this->criteria(
                q: 'ping',
                sort: '-createdAt',
                filter: 'label:contains:or',
                page: '2',
                perPage: '3',
                policy: new CollectionPaginationPolicy(mode: 'page', defaultPerPage: 5, maxPerPage: 25),
            ),
            $this->queryOver($adapter),
            self::FIELD_MAP,
        );

        $this->assertSame(
            'SELECT COUNT(*) AS __c FROM `collection_pings` '
            . 'WHERE `label` LIKE :w0 AND (`label` LIKE :g1 OR `body` LIKE :g2)',
            $adapter->executed[0]['sql'],
        );
        $this->assertSame(
            'SELECT * FROM `collection_pings` '
            . 'WHERE `label` LIKE :w0 AND (`label` LIKE :g1 OR `body` LIKE :g2) '
            . 'ORDER BY `created_at` DESC, `id` ASC LIMIT 3 OFFSET 3',
            $adapter->executed[1]['sql'],
        );
        $this->assertSame(
            ['w0' => '%or%', 'g1' => '%ping%', 'g2' => '%ping%'],
            $adapter->executed[1]['params'],
        );

        $this->assertNotNull($compiled->page);
        $this->assertSame('page', $compiled->page->mode);
        $this->assertSame(7, $compiled->page->total);
        $this->assertSame(3, $compiled->page->pageCount);
        $this->assertTrue($compiled->page->hasNext);
        $this->assertCount(3, $compiled->items);
        $this->assertContainsOnlyInstancesOf(CollectionPingResourceModel::class, $compiled->items);
    }

    #[Test]
    public function undeclared_policy_omits_the_mode_discriminator(): void
    {
        $adapter = new CollectionFakeAdapter(total: 2, rows: self::rows(2));
        $compiled = (new CollectionQueryCompiler())->compile(
            $this->criteria(),
            $this->queryOver($adapter),
            self::FIELD_MAP,
        );

        $this->assertNotNull($compiled->page);
        $this->assertNull($compiled->page->mode);
        $this->assertArrayNotHasKey('mode', $compiled->page->toArray());
    }

    #[Test]
    public function search_wildcards_in_the_term_are_escaped(): void
    {
        $adapter = new CollectionFakeAdapter();
        (new CollectionQueryCompiler())->compile(
            $this->criteria(q: '50%_done', policy: new CollectionPaginationPolicy()),
            $this->queryOver($adapter),
            self::FIELD_MAP,
        );

        $this->assertSame('%50\\%\\_done%', $adapter->executed[0]['params']['g0']);
    }

    #[Test]
    public function auto_mode_answers_page_within_the_count_threshold(): void
    {
        $adapter = new CollectionFakeAdapter(total: 7, rows: self::rows(5));
        $compiled = (new CollectionQueryCompiler())->compile(
            $this->criteria(policy: self::autoPolicy()),
            $this->queryOver($adapter),
            self::FIELD_MAP,
        );

        $this->assertNotNull($compiled->page);
        $this->assertSame('page', $compiled->page->mode);
        $this->assertNull($compiled->cursorPage);
    }

    #[Test]
    public function auto_mode_flips_to_cursor_over_the_count_threshold(): void
    {
        $adapter = new CollectionFakeAdapter(total: 19, rows: self::rows(6));
        $compiled = (new CollectionQueryCompiler())->compile(
            $this->criteria(policy: self::autoPolicy()),
            $this->queryOver($adapter),
            self::FIELD_MAP,
        );

        $this->assertNull($compiled->page);
        $this->assertNotNull($compiled->cursorPage);
        $this->assertSame(19, $compiled->cursorPage->total);
        // Peek-ahead: the window fetches perPage + 1.
        $windowSql = end($adapter->executed)['sql'];
        $this->assertStringContainsString('LIMIT 6', $windowSql);
        $this->assertTrue($compiled->cursorPage->hasNext);
        $this->assertCount(5, $compiled->items);
        $this->assertNotNull($compiled->cursorPage->nextCursor);
    }

    #[Test]
    public function auto_mode_rejects_an_explicit_page_over_the_threshold(): void
    {
        $adapter = new CollectionFakeAdapter(total: 19);

        $this->expectException(InvalidPaginationException::class);
        $this->expectExceptionMessageMatches('/exceeds the route countThreshold/');
        (new CollectionQueryCompiler())->compile(
            $this->criteria(page: '2', policy: self::autoPolicy()),
            $this->queryOver($adapter),
            self::FIELD_MAP,
        );
    }

    #[Test]
    public function first_cursor_window_encodes_a_replayable_next_cursor(): void
    {
        $adapter = new CollectionFakeAdapter(total: 19, rows: self::rows(6, stamp: '2026-06-05 12:00:00'));
        $compiled = (new CollectionQueryCompiler())->compile(
            $this->criteria(sort: '-createdAt', policy: self::autoPolicy()),
            $this->queryOver($adapter),
            self::FIELD_MAP,
        );

        $this->assertNotNull($compiled->cursorPage?->nextCursor);
        $cursor = (new CollectionCursorCodec())->decode(
            $compiled->cursorPage->nextCursor,
            '-createdAt',
            '',
        );
        // Last visible row of the perPage=5 window is row 5.
        $this->assertSame('p5', $cursor->lastId);
        $this->assertSame(['2026-06-05 12:00:00'], $cursor->lastSortKey);
    }

    #[Test]
    public function cursor_continuation_applies_a_keyset_predicate(): void
    {
        $token = (new CollectionCursorCodec())->encode(new CollectionCursor(
            version:         CollectionCursor::CURRENT_VERSION,
            sortSignature:   '-createdAt',
            filterSignature: '',
            lastSortKey:     ['2026-06-05 12:00:00'],
            lastId:          'p5',
        ));

        $adapter = new CollectionFakeAdapter(total: 19, rows: self::rows(3));
        (new CollectionQueryCompiler())->compile(
            $this->criteria(sort: '-createdAt', cursor: $token, policy: self::autoPolicy()),
            $this->queryOver($adapter),
            self::FIELD_MAP,
        );

        $windowSql = end($adapter->executed)['sql'];
        $this->assertStringContainsString(
            '(`created_at` < :raw0 OR (`created_at` = :raw1 AND `id` > :raw2))',
            $windowSql,
        );
        $this->assertStringContainsString('ORDER BY `created_at` DESC, `id` ASC LIMIT 6', $windowSql);
        $this->assertSame(
            ['raw0' => '2026-06-05 12:00:00', 'raw1' => '2026-06-05 12:00:00', 'raw2' => 'p5'],
            end($adapter->executed)['params'],
        );
    }

    #[Test]
    public function cursor_bound_to_a_different_sort_context_is_rejected(): void
    {
        $token = (new CollectionCursorCodec())->encode(new CollectionCursor(
            version:         CollectionCursor::CURRENT_VERSION,
            sortSignature:   '-createdAt',
            filterSignature: '',
            lastSortKey:     ['2026-06-05 12:00:00'],
            lastId:          'p5',
        ));

        $adapter = new CollectionFakeAdapter(total: 19);

        $this->expectException(InvalidCursorException::class);
        (new CollectionQueryCompiler())->compile(
            $this->criteria(sort: 'createdAt', cursor: $token, policy: self::autoPolicy()),
            $this->queryOver($adapter),
            self::FIELD_MAP,
        );
    }

    #[Test]
    public function single_mode_serves_everything_without_a_limit(): void
    {
        $adapter = new CollectionFakeAdapter(total: 3, rows: self::rows(3));
        $compiled = (new CollectionQueryCompiler())->compile(
            $this->criteria(policy: new CollectionPaginationPolicy(mode: 'single')),
            $this->queryOver($adapter),
            self::FIELD_MAP,
        );

        $windowSql = end($adapter->executed)['sql'];
        $this->assertStringNotContainsString('LIMIT', $windowSql);
        $this->assertNotNull($compiled->page);
        $this->assertSame('single', $compiled->page->mode);
        $this->assertSame(3, $compiled->page->total);
        $this->assertFalse($compiled->page->hasNext);
    }

    #[Test]
    public function unsupported_source_is_rejected(): void
    {
        $compiler = new CollectionQueryCompiler();

        $this->assertFalse($compiler->supports(new \stdClass()));
        $this->expectException(\InvalidArgumentException::class);
        $compiler->compile($this->criteria(), new \stdClass(), self::FIELD_MAP);
    }
}

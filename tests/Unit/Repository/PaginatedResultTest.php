<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Repository\PaginatedResult;

final class PaginatedResultTest extends TestCase
{
    #[Test]
    public function empty_result_has_last_page_one_not_zero(): void
    {
        $result = new PaginatedResult(items: [], total: 0, page: 1, perPage: 10);

        $this->assertSame(1, $result->lastPage);
        $this->assertTrue($result->isEmpty());
        $this->assertFalse($result->hasNextPage());
        $this->assertFalse($result->hasPreviousPage());
    }

    #[Test]
    public function last_page_rounds_up_when_total_does_not_divide_evenly(): void
    {
        $result = new PaginatedResult(items: [new \stdClass()], total: 21, page: 1, perPage: 10);

        $this->assertSame(3, $result->lastPage);
        $this->assertTrue($result->hasNextPage());
    }

    #[Test]
    public function map_preserves_pagination_metadata(): void
    {
        $a = new \stdClass();
        $a->v = 1;
        $b = new \stdClass();
        $b->v = 2;

        $result = new PaginatedResult(items: [$a, $b], total: 2, page: 1, perPage: 2);
        $doubled = $result->map(static function (\stdClass $item): \stdClass {
            $out = new \stdClass();
            $out->v = $item->v * 2;
            return $out;
        });

        $this->assertCount(2, $doubled->items);
        $this->assertSame(2, $doubled->items[0]->v);
        $this->assertSame(4, $doubled->items[1]->v);
        $this->assertSame(2, $doubled->total);
        $this->assertSame(1, $doubled->page);
        $this->assertSame(2, $doubled->perPage);
        $this->assertSame(1, $doubled->lastPage);
    }

    #[Test]
    public function zero_per_page_does_not_divide_by_zero(): void
    {
        $result = new PaginatedResult(items: [], total: 10, page: 1, perPage: 0);

        $this->assertSame(1, $result->lastPage);
    }
}

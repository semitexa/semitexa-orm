<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Orm\Application\Service\Schema\SchemaCollector;
use Semitexa\Orm\Domain\Model\IndexDefinition;
use Semitexa\Orm\Tests\Fixture\Schema\SharedColumnBaseModel;
use Semitexa\Orm\Tests\Fixture\Schema\SharedColumnOtherSliceModel;
use Semitexa\Orm\Tests\Fixture\Schema\SharedColumnSliceModel;

/**
 * A #[Filterable] index is collected whichever resource on a shared table
 * declares the column first. The collector returned at the column the first
 * resource had already defined, before it reached the index, so a slice that
 * filters by a column its base declares plainly got no index (review,
 * semitexa-orm#123).
 */
final class SchemaCollectorSharedColumnIndexTest extends TestCase
{
    /**
     * @param list<string> $classes
     * @return IndexDefinition[]
     */
    private function indexesOf(array $classes): array
    {
        $discovery = $this->createMock(ClassDiscovery::class);
        $discovery->method('findClassesWithAttribute')->willReturn($classes);
        $tables = (new SchemaCollector(classDiscovery: $discovery))->collect();

        return $tables['shared_column_items']->getIndexes();
    }

    #[Test]
    public function the_slice_that_filters_gets_its_index_when_the_base_declared_the_column_first(): void
    {
        self::assertEquals(
            [new IndexDefinition(columns: ['status'], unique: false, name: 'idx_shared_column_items_status')],
            $this->indexesOf([SharedColumnBaseModel::class, SharedColumnSliceModel::class]),
        );
    }

    #[Test]
    public function the_order_of_discovery_does_not_change_the_index(): void
    {
        self::assertEquals(
            [new IndexDefinition(columns: ['status'], unique: false, name: 'idx_shared_column_items_status')],
            $this->indexesOf([SharedColumnSliceModel::class, SharedColumnBaseModel::class]),
        );
    }

    #[Test]
    public function two_resources_that_both_filter_by_the_column_give_it_one_index(): void
    {
        self::assertEquals(
            [new IndexDefinition(columns: ['status'], unique: false, name: 'idx_shared_column_items_status')],
            $this->indexesOf([SharedColumnSliceModel::class, SharedColumnOtherSliceModel::class]),
        );
    }
}

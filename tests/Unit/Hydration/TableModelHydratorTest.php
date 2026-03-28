<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Hydration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Hydration\RelationState;
use Semitexa\Orm\Hydration\TableModelHydrator;
use Semitexa\Orm\Tests\Fixture\Hydration\HydratableProductTableModel;

require_once __DIR__ . '/../../Fixture/Metadata/ValidCategoryTableModel.php';
require_once __DIR__ . '/../../Fixture/Metadata/ValidReviewTableModel.php';
require_once __DIR__ . '/../../Fixture/Hydration/HydratableProductTableModel.php';

final class TableModelHydratorTest extends TestCase
{
    #[Test]
    public function hydrates_a_readonly_table_model_from_a_db_row(): void
    {
        $hydrator = new TableModelHydrator();

        $tableModel = $hydrator->hydrate([
            'id' => 'product-1',
            'tenantId' => 'tenant-1',
            'name' => 'Product 1',
            'categoryId' => 'category-1',
            'deletedAt' => null,
        ], HydratableProductTableModel::class);

        $this->assertInstanceOf(HydratableProductTableModel::class, $tableModel);
        $this->assertSame('product-1', $tableModel->id);
        $this->assertSame('tenant-1', $tableModel->tenantId);
        $this->assertSame('Product 1', $tableModel->name);
        $this->assertSame('category-1', $tableModel->categoryId);
        $this->assertNull($tableModel->deletedAt);
        $this->assertInstanceOf(RelationState::class, $tableModel->category);
        $this->assertInstanceOf(RelationState::class, $tableModel->reviews);
        $this->assertTrue($tableModel->category->isNotLoaded());
        $this->assertTrue($tableModel->reviews->isNotLoaded());
    }

    #[Test]
    public function dehydrates_a_table_model_to_db_row(): void
    {
        $hydrator = new TableModelHydrator();
        $tableModel = new HydratableProductTableModel(
            id: 'product-2',
            tenantId: 'tenant-2',
            name: 'Product 2',
            categoryId: 'category-2',
            deletedAt: null,
            category: RelationState::notLoaded(),
            reviews: RelationState::loadedEmptyCollection(),
        );

        $row = $hydrator->dehydrate($tableModel);

        $this->assertSame([
            'id' => 'product-2',
            'tenantId' => 'tenant-2',
            'name' => 'Product 2',
            'categoryId' => 'category-2',
            'deletedAt' => null,
        ], $row);
    }

    #[Test]
    public function fails_when_a_required_column_is_missing(): void
    {
        $hydrator = new TableModelHydrator();

        $this->expectException(\InvalidArgumentException::class);

        $hydrator->hydrate([
            'id' => 'product-3',
            'tenantId' => 'tenant-3',
            'name' => 'Product 3',
        ], HydratableProductTableModel::class);
    }
}

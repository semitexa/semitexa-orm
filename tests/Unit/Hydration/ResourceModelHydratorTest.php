<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Hydration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Hydration\RelationState;
use Semitexa\Orm\Hydration\ResourceModelHydrator;
use Semitexa\Orm\Tests\Fixture\Hydration\HydratableProductResourceModel;

final class ResourceModelHydratorTest extends TestCase
{
    #[Test]
    public function hydrates_a_readonly_resource_model_from_a_db_row(): void
    {
        $hydrator = new ResourceModelHydrator();

        $resourceModel = $hydrator->hydrate([
            'id' => 'product-1',
            'tenantId' => 'tenant-1',
            'name' => 'Product 1',
            'categoryId' => 'category-1',
            'deletedAt' => null,
        ], HydratableProductResourceModel::class);

        $this->assertInstanceOf(HydratableProductResourceModel::class, $resourceModel);
        $this->assertSame('product-1', $resourceModel->id);
        $this->assertSame('tenant-1', $resourceModel->tenantId);
        $this->assertSame('Product 1', $resourceModel->name);
        $this->assertSame('category-1', $resourceModel->categoryId);
        $this->assertNull($resourceModel->deletedAt);
        $this->assertInstanceOf(RelationState::class, $resourceModel->category);
        $this->assertInstanceOf(RelationState::class, $resourceModel->reviews);
        $this->assertTrue($resourceModel->category->isNotLoaded());
        $this->assertTrue($resourceModel->reviews->isNotLoaded());
    }

    #[Test]
    public function dehydrates_a_resource_model_to_db_row(): void
    {
        $hydrator = new ResourceModelHydrator();
        $resourceModel = new HydratableProductResourceModel(
            id: 'product-2',
            tenantId: 'tenant-2',
            name: 'Product 2',
            categoryId: 'category-2',
            deletedAt: null,
            category: RelationState::notLoaded(),
            reviews: RelationState::loadedEmptyCollection(),
        );

        $row = $hydrator->dehydrate($resourceModel);

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
        $hydrator = new ResourceModelHydrator();

        $this->expectException(\InvalidArgumentException::class);

        $hydrator->hydrate([
            'id' => 'product-3',
            'tenantId' => 'tenant-3',
            'name' => 'Product 3',
        ], HydratableProductResourceModel::class);
    }
}

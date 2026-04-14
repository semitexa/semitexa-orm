<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Hydration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Hydration\ResourceModelHydrator;
use Semitexa\Orm\Hydration\ResourceModelRelationLoader;
use Semitexa\Orm\Tests\Fixture\Hydration\FakeDatabaseAdapter;

use Semitexa\Orm\Tests\Fixture\Hydration\HydratableProductResourceModel;

use Semitexa\Orm\Tests\Fixture\Metadata\ValidCategoryResourceModel;

use Semitexa\Orm\Tests\Fixture\Metadata\ValidReviewResourceModel;

final class ResourceModelRelationLoaderTest extends TestCase
{
    #[Test]
    public function eager_loads_belongs_to_and_has_many_relations_in_batches(): void
    {
        $adapter = new FakeDatabaseAdapter([
            'SELECT * FROM `categories` WHERE `id` IN (?, ?)' => [
                ['id' => 'category-1', 'name' => 'Category 1'],
                ['id' => 'category-2', 'name' => 'Category 2'],
            ],
            'SELECT * FROM `reviews` WHERE `productId` IN (?, ?)' => [
                ['id' => 'review-1', 'productId' => 'product-1', 'rating' => 5],
                ['id' => 'review-2', 'productId' => 'product-1', 'rating' => 4],
                ['id' => 'review-3', 'productId' => 'product-2', 'rating' => 3],
            ],
        ]);

        $hydrator = new ResourceModelHydrator();
        $loader = new ResourceModelRelationLoader($adapter, $hydrator);

        $products = [
            $hydrator->hydrate([
                'id' => 'product-1',
                'tenantId' => 'tenant-1',
                'name' => 'Product 1',
                'categoryId' => 'category-1',
                'deletedAt' => null,
            ], HydratableProductResourceModel::class),
            $hydrator->hydrate([
                'id' => 'product-2',
                'tenantId' => 'tenant-1',
                'name' => 'Product 2',
                'categoryId' => 'category-2',
                'deletedAt' => null,
            ], HydratableProductResourceModel::class),
        ];

        $loader->loadRelations($products, HydratableProductResourceModel::class);

        $this->assertCount(2, $adapter->executed);

        $product1 = $products[0];
        $product2 = $products[1];

        $this->assertTrue($product1->category->isLoaded());
        $this->assertTrue($product1->reviews->isLoaded());
        $this->assertInstanceOf(ValidCategoryResourceModel::class, $product1->category->value());
        $this->assertSame('Category 1', $product1->category->value()->name);
        $this->assertCount(2, $product1->reviews->value());
        $this->assertContainsOnlyInstancesOf(ValidReviewResourceModel::class, $product1->reviews->value());

        $this->assertInstanceOf(ValidCategoryResourceModel::class, $product2->category->value());
        $this->assertSame('Category 2', $product2->category->value()->name);
        $this->assertCount(1, $product2->reviews->value());
    }

    #[Test]
    public function only_requested_relations_are_loaded(): void
    {
        $adapter = new FakeDatabaseAdapter([
            'SELECT * FROM `categories` WHERE `id` IN (?)' => [
                ['id' => 'category-1', 'name' => 'Category 1'],
            ],
        ]);

        $hydrator = new ResourceModelHydrator();
        $loader = new ResourceModelRelationLoader($adapter, $hydrator);
        $product = $hydrator->hydrate([
            'id' => 'product-1',
            'tenantId' => 'tenant-1',
            'name' => 'Product 1',
            'categoryId' => 'category-1',
            'deletedAt' => null,
        ], HydratableProductResourceModel::class);

        $loader->loadRelations([$product], HydratableProductResourceModel::class, ['category']);

        $this->assertTrue($product->category->isLoaded());
        $this->assertTrue($product->reviews->isNotLoaded());
        $this->assertCount(1, $adapter->executed);
    }
}

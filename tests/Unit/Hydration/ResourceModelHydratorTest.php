<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Hydration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Domain\Model\RelationState;
use Semitexa\Orm\Application\Service\Hydration\ResourceModelHydrator;
use Semitexa\Orm\Tests\Fixture\Hydration\HydratableProductResourceModel;

final class ResourceModelHydratorTest extends TestCase
{
    #[Test]
    public function each_hydrated_row_gets_its_own_relation_state_not_a_shared_instance(): void
    {
        // The per-class hydration plan is memoized, but a RelationState relation
        // default must still be a FRESH instance per row — never a shared object
        // that two rows could alias. This pins that invariant so a future
        // "cache the default value too" refactor can't silently introduce
        // cross-row aliasing.
        $hydrator = new ResourceModelHydrator();
        $row = ['id' => 'p', 'tenantId' => 't', 'name' => 'n', 'categoryId' => 'c', 'deletedAt' => null];

        $a = $hydrator->hydrate($row, HydratableProductResourceModel::class);
        $b = $hydrator->hydrate($row, HydratableProductResourceModel::class);

        $this->assertInstanceOf(RelationState::class, $a->category);
        $this->assertInstanceOf(RelationState::class, $b->category);
        $this->assertNotSame($a->category, $b->category, 'relation state must not be shared across rows');
        $this->assertNotSame($a->reviews, $b->reviews);
    }

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
    public function dehydrate_reuses_the_cached_plan_across_distinct_instances(): void
    {
        // The dehydration plan (cached ReflectionProperty + ColumnDefinition per
        // column) is memoized per class; a ReflectionProperty is class-bound, not
        // instance-bound. Two different instances must still dehydrate to their
        // OWN values through the shared cached plan.
        $hydrator = new ResourceModelHydrator();
        $a = new HydratableProductResourceModel('a', 't-a', 'Alpha', 'cat-a', null, RelationState::notLoaded(), RelationState::notLoaded());
        $b = new HydratableProductResourceModel('b', 't-b', 'Beta', 'cat-b', null, RelationState::notLoaded(), RelationState::notLoaded());

        $rowA = $hydrator->dehydrate($a);
        $rowB = $hydrator->dehydrate($b);

        $this->assertSame('a', $rowA['id']);
        $this->assertSame('Alpha', $rowA['name']);
        $this->assertSame('b', $rowB['id']);
        $this->assertSame('Beta', $rowB['name']);
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

<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Hydration\ResourceModelHydrator;
use Semitexa\Orm\Hydration\ResourceModelRelationLoader;
use Semitexa\Orm\Mapping\MapperRegistry;
use Semitexa\Orm\Query\Direction;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Query\SystemScopeToken;
use Semitexa\Orm\Query\ResourceModelQuery;
use Semitexa\Orm\Tests\Fixture\Hydration\FakeDatabaseAdapter;

use Semitexa\Orm\Tests\Fixture\Hydration\HydratableProductResourceModel;

use Semitexa\Orm\Tests\Fixture\Metadata\MappedTenantPropertyResourceModel;

use Semitexa\Orm\Tests\Fixture\Metadata\ValidCategoryResourceModel;

use Semitexa\Orm\Tests\Fixture\Mapping\HydratableProductDomainModel;

use Semitexa\Orm\Tests\Fixture\Mapping\HydratableProductMapper;

use Semitexa\Orm\Tests\Fixture\Metadata\ValidReviewResourceModel;

final class ResourceModelQueryTest extends TestCase
{
    #[Test]
    public function builds_sql_and_params_from_validated_column_refs(): void
    {
        $adapter = new FakeDatabaseAdapter([]);
        $hydrator = new ResourceModelHydrator();
        $relationLoader = new ResourceModelRelationLoader($adapter, $hydrator);

        $query = new ResourceModelQuery(
            HydratableProductResourceModel::class,
            $adapter,
            $hydrator,
            $relationLoader,
        );

        $query
            ->forTenant('tenant-1')
            ->where(HydratableProductResourceModel::column('tenantId'), Operator::Equals, 'tenant-1')
            ->where(HydratableProductResourceModel::column('name'), Operator::Like, '%Product%')
            ->orderBy(HydratableProductResourceModel::column('name'), Direction::Asc)
            ->limit(20)
            ->offset(10);

        $this->assertSame(
            'SELECT * FROM `hydratable_products` WHERE `tenantId` = :tenant_scope AND `deletedAt` IS NULL AND `tenantId` = :w0 AND `name` LIKE :w1 ORDER BY `name` ASC LIMIT 20 OFFSET 10',
            $query->toSql(),
        );
        $this->assertSame(
            ['tenant_scope' => 'tenant-1', 'w0' => 'tenant-1', 'w1' => '%Product%'],
            $query->toParams(),
        );
    }

    #[Test]
    public function fetch_all_hydrates_rows_and_eager_loads_requested_relations(): void
    {
        $adapter = new FakeDatabaseAdapter([
            'SELECT * FROM `hydratable_products` WHERE `tenantId` = :tenant_scope AND `deletedAt` IS NULL AND `tenantId` = :w0 ORDER BY `name` ASC' => [
                [
                    'id' => 'product-1',
                    'tenantId' => 'tenant-1',
                    'name' => 'Product 1',
                    'categoryId' => 'category-1',
                    'deletedAt' => null,
                ],
                [
                    'id' => 'product-2',
                    'tenantId' => 'tenant-1',
                    'name' => 'Product 2',
                    'categoryId' => 'category-2',
                    'deletedAt' => null,
                ],
            ],
            'SELECT * FROM `categories` WHERE `id` IN (:in_0, :in_1)' => [
                ['id' => 'category-1', 'name' => 'Category 1'],
                ['id' => 'category-2', 'name' => 'Category 2'],
            ],
            'SELECT * FROM `reviews` WHERE `productId` IN (:in_0, :in_1)' => [
                ['id' => 'review-1', 'productId' => 'product-1', 'rating' => 5],
                ['id' => 'review-2', 'productId' => 'product-2', 'rating' => 4],
            ],
        ]);

        $hydrator = new ResourceModelHydrator();
        $relationLoader = new ResourceModelRelationLoader($adapter, $hydrator);
        $query = new ResourceModelQuery(
            HydratableProductResourceModel::class,
            $adapter,
            $hydrator,
            $relationLoader,
        );

        $items = $query
            ->forTenant('tenant-1')
            ->where(HydratableProductResourceModel::column('tenantId'), Operator::Equals, 'tenant-1')
            ->orderBy(HydratableProductResourceModel::column('name'), Direction::Asc)
            ->withRelation(HydratableProductResourceModel::relation('category'))
            ->withRelation(HydratableProductResourceModel::relation('reviews'))
            ->fetchAll();

        $this->assertCount(2, $items);
        $this->assertCount(3, $adapter->executed);
        $this->assertContainsOnlyInstancesOf(HydratableProductResourceModel::class, $items);

        $first = $items[0];
        $second = $items[1];

        $this->assertTrue($first->category->isLoaded());
        $this->assertTrue($first->reviews->isLoaded());
        $this->assertInstanceOf(ValidCategoryResourceModel::class, $first->category->value());
        $this->assertSame('Category 1', $first->category->value()->name);
        $this->assertContainsOnlyInstancesOf(ValidReviewResourceModel::class, $first->reviews->value());
        $this->assertCount(1, $first->reviews->value());

        $this->assertTrue($second->category->isLoaded());
        $this->assertSame('Category 2', $second->category->value()->name);
        $this->assertCount(1, $second->reviews->value());
    }

    #[Test]
    public function fetch_one_applies_limit_and_returns_first_result(): void
    {
        $adapter = new FakeDatabaseAdapter([
            'SELECT * FROM `hydratable_products` WHERE `tenantId` = :tenant_scope AND `deletedAt` IS NULL AND `id` = :w0 LIMIT 1' => [
                [
                    'id' => 'product-1',
                    'tenantId' => 'tenant-1',
                    'name' => 'Product 1',
                    'categoryId' => 'category-1',
                    'deletedAt' => null,
                ],
            ],
        ]);

        $hydrator = new ResourceModelHydrator();
        $relationLoader = new ResourceModelRelationLoader($adapter, $hydrator);
        $query = new ResourceModelQuery(
            HydratableProductResourceModel::class,
            $adapter,
            $hydrator,
            $relationLoader,
        );

        $item = $query
            ->forTenant('tenant-1')
            ->where(HydratableProductResourceModel::column('id'), Operator::Equals, 'product-1')
            ->fetchOne();

        $this->assertInstanceOf(HydratableProductResourceModel::class, $item);
        $this->assertSame('product-1', $item->id);
        $this->assertCount(1, $adapter->executed);
        $this->assertSame(
            'SELECT * FROM `hydratable_products` WHERE `tenantId` = :tenant_scope AND `deletedAt` IS NULL AND `id` = :w0 LIMIT 1',
            $adapter->executed[0]['sql'],
        );
    }

    #[Test]
    public function rejects_column_and_relation_refs_from_other_resource_models(): void
    {
        $adapter = new FakeDatabaseAdapter([]);
        $hydrator = new ResourceModelHydrator();
        $relationLoader = new ResourceModelRelationLoader($adapter, $hydrator);
        $query = new ResourceModelQuery(
            HydratableProductResourceModel::class,
            $adapter,
            $hydrator,
            $relationLoader,
        );

        $this->expectException(\InvalidArgumentException::class);
        $query->where(ValidCategoryResourceModel::column('name'), Operator::Equals, 'Category 1');
    }

    #[Test]
    public function tenant_scoped_query_requires_tenant_context_by_default(): void
    {
        $adapter = new FakeDatabaseAdapter([]);
        $hydrator = new ResourceModelHydrator();
        $relationLoader = new ResourceModelRelationLoader($adapter, $hydrator);
        $query = new ResourceModelQuery(
            HydratableProductResourceModel::class,
            $adapter,
            $hydrator,
            $relationLoader,
        );

        $this->expectException(\LogicException::class);
        $query->toSql();
    }

    #[Test]
    public function resolves_tenant_scope_property_to_declared_column_name(): void
    {
        $adapter = new FakeDatabaseAdapter([]);
        $hydrator = new ResourceModelHydrator();
        $relationLoader = new ResourceModelRelationLoader($adapter, $hydrator);
        $query = new ResourceModelQuery(
            MappedTenantPropertyResourceModel::class,
            $adapter,
            $hydrator,
            $relationLoader,
        );

        $query->forTenant('tenant-1');

        $this->assertSame(
            'SELECT * FROM `mapped_tenant_property_models` WHERE `tenant_id` = :tenant_scope',
            $query->toSql(),
        );
        $this->assertSame(['tenant_scope' => 'tenant-1'], $query->toParams());
    }

    #[Test]
    public function can_override_soft_delete_and_tenant_scope_policies_explicitly(): void
    {
        $adapter = new FakeDatabaseAdapter([]);
        $hydrator = new ResourceModelHydrator();
        $relationLoader = new ResourceModelRelationLoader($adapter, $hydrator);
        $query = new ResourceModelQuery(
            HydratableProductResourceModel::class,
            $adapter,
            $hydrator,
            $relationLoader,
        );

        $query
            ->withoutTenantScope(SystemScopeToken::issue())
            ->onlySoftDeleted();

        $this->assertSame(
            'SELECT * FROM `hydratable_products` WHERE `deletedAt` IS NOT NULL',
            $query->toSql(),
        );
        $this->assertSame([], $query->toParams());
    }

    #[Test]
    public function supports_explicit_null_predicates_without_bound_params(): void
    {
        $adapter = new FakeDatabaseAdapter([]);
        $hydrator = new ResourceModelHydrator();
        $relationLoader = new ResourceModelRelationLoader($adapter, $hydrator);
        $query = new ResourceModelQuery(
            HydratableProductResourceModel::class,
            $adapter,
            $hydrator,
            $relationLoader,
        );

        $query
            ->forTenant('tenant-1')
            ->whereNull(HydratableProductResourceModel::column('deletedAt'))
            ->whereNotNull(HydratableProductResourceModel::column('categoryId'));

        $this->assertSame(
            'SELECT * FROM `hydratable_products` WHERE `tenantId` = :tenant_scope AND `deletedAt` IS NULL AND `deletedAt` IS NULL AND `categoryId` IS NOT NULL',
            $query->toSql(),
        );
        $this->assertSame(['tenant_scope' => 'tenant-1'], $query->toParams());
    }

    #[Test]
    public function fetch_all_as_maps_resource_models_into_domain_models_via_registry(): void
    {
        $adapter = new FakeDatabaseAdapter([
            'SELECT * FROM `hydratable_products` WHERE `tenantId` = :tenant_scope AND `deletedAt` IS NULL ORDER BY `name` ASC' => [
                [
                    'id' => 'product-1',
                    'tenantId' => 'tenant-1',
                    'name' => 'Product 1',
                    'categoryId' => 'category-1',
                    'deletedAt' => null,
                ],
            ],
        ]);

        $hydrator = new ResourceModelHydrator();
        $relationLoader = new ResourceModelRelationLoader($adapter, $hydrator);
        $query = new ResourceModelQuery(
            HydratableProductResourceModel::class,
            $adapter,
            $hydrator,
            $relationLoader,
        );
        $registry = new MapperRegistry();
        $registry->build(
            mapperClasses: [HydratableProductMapper::class],
            domainModelClasses: [HydratableProductDomainModel::class],
        );

        $items = $query
            ->forTenant('tenant-1')
            ->orderBy(HydratableProductResourceModel::column('name'), Direction::Asc)
            ->fetchAllAs(HydratableProductDomainModel::class, $registry);

        $this->assertCount(1, $items);
        $this->assertContainsOnlyInstancesOf(HydratableProductDomainModel::class, $items);
        $this->assertSame('product-1', $items[0]->id);
        $this->assertSame('tenant-1', $items[0]->tenantId);
    }
}

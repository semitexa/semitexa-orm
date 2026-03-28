<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Hydration\TableModelHydrator;
use Semitexa\Orm\Hydration\TableModelRelationLoader;
use Semitexa\Orm\Mapping\MapperRegistry;
use Semitexa\Orm\Query\Direction;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Query\SystemScopeToken;
use Semitexa\Orm\Query\TableModelQuery;
use Semitexa\Orm\Tests\Fixture\Hydration\FakeDatabaseAdapter;
use Semitexa\Orm\Tests\Fixture\Hydration\HydratableProductTableModel;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidCategoryTableModel;
use Semitexa\Orm\Tests\Fixture\Mapping\HydratableProductDomainModel;
use Semitexa\Orm\Tests\Fixture\Mapping\HydratableProductMapper;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidReviewTableModel;

require_once __DIR__ . '/../../Fixture/Metadata/ValidCategoryTableModel.php';
require_once __DIR__ . '/../../Fixture/Metadata/ValidReviewTableModel.php';
require_once __DIR__ . '/../../Fixture/Hydration/HydratableProductTableModel.php';
require_once __DIR__ . '/../../Fixture/Hydration/FakeDatabaseAdapter.php';
require_once __DIR__ . '/../../Fixture/Mapping/HydratableProductDomainModel.php';
require_once __DIR__ . '/../../Fixture/Mapping/HydratableProductMapper.php';

final class TableModelQueryTest extends TestCase
{
    #[Test]
    public function builds_sql_and_params_from_validated_column_refs(): void
    {
        $adapter = new FakeDatabaseAdapter([]);
        $hydrator = new TableModelHydrator();
        $relationLoader = new TableModelRelationLoader($adapter, $hydrator);

        $query = new TableModelQuery(
            HydratableProductTableModel::class,
            $adapter,
            $hydrator,
            $relationLoader,
        );

        $query
            ->forTenant('tenant-1')
            ->where(HydratableProductTableModel::column('tenantId'), Operator::Equals, 'tenant-1')
            ->where(HydratableProductTableModel::column('name'), Operator::Like, '%Product%')
            ->orderBy(HydratableProductTableModel::column('name'), Direction::Asc)
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
            'SELECT * FROM `categories` WHERE `id` IN (?, ?)' => [
                ['id' => 'category-1', 'name' => 'Category 1'],
                ['id' => 'category-2', 'name' => 'Category 2'],
            ],
            'SELECT * FROM `reviews` WHERE `productId` IN (?, ?)' => [
                ['id' => 'review-1', 'productId' => 'product-1', 'rating' => 5],
                ['id' => 'review-2', 'productId' => 'product-2', 'rating' => 4],
            ],
        ]);

        $hydrator = new TableModelHydrator();
        $relationLoader = new TableModelRelationLoader($adapter, $hydrator);
        $query = new TableModelQuery(
            HydratableProductTableModel::class,
            $adapter,
            $hydrator,
            $relationLoader,
        );

        $items = $query
            ->forTenant('tenant-1')
            ->where(HydratableProductTableModel::column('tenantId'), Operator::Equals, 'tenant-1')
            ->orderBy(HydratableProductTableModel::column('name'), Direction::Asc)
            ->withRelation(HydratableProductTableModel::relation('category'))
            ->withRelation(HydratableProductTableModel::relation('reviews'))
            ->fetchAll();

        $this->assertCount(2, $items);
        $this->assertCount(3, $adapter->executed);
        $this->assertContainsOnlyInstancesOf(HydratableProductTableModel::class, $items);

        $first = $items[0];
        $second = $items[1];

        $this->assertTrue($first->category->isLoaded());
        $this->assertTrue($first->reviews->isLoaded());
        $this->assertInstanceOf(ValidCategoryTableModel::class, $first->category->value());
        $this->assertSame('Category 1', $first->category->value()->name);
        $this->assertContainsOnlyInstancesOf(ValidReviewTableModel::class, $first->reviews->value());
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

        $hydrator = new TableModelHydrator();
        $relationLoader = new TableModelRelationLoader($adapter, $hydrator);
        $query = new TableModelQuery(
            HydratableProductTableModel::class,
            $adapter,
            $hydrator,
            $relationLoader,
        );

        $item = $query
            ->forTenant('tenant-1')
            ->where(HydratableProductTableModel::column('id'), Operator::Equals, 'product-1')
            ->fetchOne();

        $this->assertInstanceOf(HydratableProductTableModel::class, $item);
        $this->assertSame('product-1', $item->id);
        $this->assertCount(1, $adapter->executed);
        $this->assertSame(
            'SELECT * FROM `hydratable_products` WHERE `tenantId` = :tenant_scope AND `deletedAt` IS NULL AND `id` = :w0 LIMIT 1',
            $adapter->executed[0]['sql'],
        );
    }

    #[Test]
    public function rejects_column_and_relation_refs_from_other_table_models(): void
    {
        $adapter = new FakeDatabaseAdapter([]);
        $hydrator = new TableModelHydrator();
        $relationLoader = new TableModelRelationLoader($adapter, $hydrator);
        $query = new TableModelQuery(
            HydratableProductTableModel::class,
            $adapter,
            $hydrator,
            $relationLoader,
        );

        $this->expectException(\InvalidArgumentException::class);
        $query->where(ValidCategoryTableModel::column('name'), Operator::Equals, 'Category 1');
    }

    #[Test]
    public function tenant_scoped_query_requires_tenant_context_by_default(): void
    {
        $adapter = new FakeDatabaseAdapter([]);
        $hydrator = new TableModelHydrator();
        $relationLoader = new TableModelRelationLoader($adapter, $hydrator);
        $query = new TableModelQuery(
            HydratableProductTableModel::class,
            $adapter,
            $hydrator,
            $relationLoader,
        );

        $this->expectException(\LogicException::class);
        $query->toSql();
    }

    #[Test]
    public function can_override_soft_delete_and_tenant_scope_policies_explicitly(): void
    {
        $adapter = new FakeDatabaseAdapter([]);
        $hydrator = new TableModelHydrator();
        $relationLoader = new TableModelRelationLoader($adapter, $hydrator);
        $query = new TableModelQuery(
            HydratableProductTableModel::class,
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
        $hydrator = new TableModelHydrator();
        $relationLoader = new TableModelRelationLoader($adapter, $hydrator);
        $query = new TableModelQuery(
            HydratableProductTableModel::class,
            $adapter,
            $hydrator,
            $relationLoader,
        );

        $query
            ->forTenant('tenant-1')
            ->whereNull(HydratableProductTableModel::column('deletedAt'))
            ->whereNotNull(HydratableProductTableModel::column('categoryId'));

        $this->assertSame(
            'SELECT * FROM `hydratable_products` WHERE `tenantId` = :tenant_scope AND `deletedAt` IS NULL AND `deletedAt` IS NULL AND `categoryId` IS NOT NULL',
            $query->toSql(),
        );
        $this->assertSame(['tenant_scope' => 'tenant-1'], $query->toParams());
    }

    #[Test]
    public function fetch_all_as_maps_table_models_into_domain_models_via_registry(): void
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

        $hydrator = new TableModelHydrator();
        $relationLoader = new TableModelRelationLoader($adapter, $hydrator);
        $query = new TableModelQuery(
            HydratableProductTableModel::class,
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
            ->orderBy(HydratableProductTableModel::column('name'), Direction::Asc)
            ->fetchAllAs(HydratableProductDomainModel::class, $registry);

        $this->assertCount(1, $items);
        $this->assertContainsOnlyInstancesOf(HydratableProductDomainModel::class, $items);
        $this->assertSame('product-1', $items[0]->id);
        $this->assertSame('tenant-1', $items[0]->tenantId);
    }
}

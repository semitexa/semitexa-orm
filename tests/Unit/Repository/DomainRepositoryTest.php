<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Hydration\TableModelHydrator;
use Semitexa\Orm\Hydration\TableModelRelationLoader;
use Semitexa\Orm\Mapping\MapperRegistry;
use Semitexa\Orm\Query\Direction;
use Semitexa\Orm\Query\SystemScopeToken;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Orm\Tests\Fixture\Hydration\FakeDatabaseAdapter;
use Semitexa\Orm\Tests\Fixture\Hydration\HydratableProductTableModel;
use Semitexa\Orm\Tests\Fixture\Mapping\HydratableProductDomainModel;
use Semitexa\Orm\Tests\Fixture\Mapping\HydratableProductMapper;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidCategoryTableModel;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidReviewTableModel;
use Semitexa\Orm\Tests\Fixture\Persistence\PersistableProductDomainModel;
use Semitexa\Orm\Tests\Fixture\Persistence\PersistableProductMapper;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidProductTableModel;

require_once __DIR__ . '/../../Fixture/Metadata/ValidCategoryTableModel.php';
require_once __DIR__ . '/../../Fixture/Metadata/ValidReviewTableModel.php';
require_once __DIR__ . '/../../Fixture/Metadata/ValidProductTableModel.php';
require_once __DIR__ . '/../../Fixture/Hydration/HydratableProductTableModel.php';
require_once __DIR__ . '/../../Fixture/Hydration/FakeDatabaseAdapter.php';
require_once __DIR__ . '/../../Fixture/Mapping/HydratableProductDomainModel.php';
require_once __DIR__ . '/../../Fixture/Mapping/HydratableProductMapper.php';
require_once __DIR__ . '/../../Fixture/Persistence/PersistableCategoryDomainModel.php';
require_once __DIR__ . '/../../Fixture/Persistence/PersistableReviewDomainModel.php';
require_once __DIR__ . '/../../Fixture/Persistence/PersistableProductDomainModel.php';
require_once __DIR__ . '/../../Fixture/Persistence/PersistableProductMapper.php';

final class DomainRepositoryTest extends TestCase
{
    #[Test]
    public function find_by_id_returns_mapped_domain_model(): void
    {
        $adapter = new FakeDatabaseAdapter([
            'SELECT * FROM `hydratable_products` WHERE `tenantId` = :tenant_scope AND `deletedAt` IS NULL AND `id` = :w0 LIMIT 1' => [[
                'id' => 'product-1',
                'tenantId' => 'tenant-1',
                'name' => 'Product 1',
                'categoryId' => 'category-1',
                'deletedAt' => null,
            ]],
        ]);

        $repository = $this->hydratableRepository($adapter)->forTenant('tenant-1');
        $item = $repository->findById('product-1');

        $this->assertInstanceOf(HydratableProductDomainModel::class, $item);
        $this->assertSame('product-1', $item->id);
    }

    #[Test]
    public function find_by_supports_relations_and_limit(): void
    {
        $adapter = new FakeDatabaseAdapter([
            'SELECT * FROM `hydratable_products` WHERE `tenantId` = :tenant_scope AND `deletedAt` IS NULL AND `tenantId` = :w0 LIMIT 10' => [[
                'id' => 'product-1',
                'tenantId' => 'tenant-1',
                'name' => 'Product 1',
                'categoryId' => 'category-1',
                'deletedAt' => null,
            ]],
            'SELECT * FROM `categories` WHERE `id` IN (?)' => [
                ['id' => 'category-1', 'name' => 'Category 1'],
            ],
        ]);

        $repository = $this->hydratableRepository($adapter)->forTenant('tenant-1');
        $items = $repository->findBy(
            ['tenantId' => 'tenant-1'],
            relations: [HydratableProductTableModel::relation('category')],
            limit: 10,
        );

        $this->assertCount(1, $items);
        $this->assertInstanceOf(HydratableProductDomainModel::class, $items[0]);
        $this->assertCount(2, $adapter->executed);
    }

    #[Test]
    public function query_surface_can_be_used_directly_with_explicit_ordering(): void
    {
        $adapter = new FakeDatabaseAdapter([
            'SELECT * FROM `hydratable_products` WHERE `tenantId` = :tenant_scope AND `deletedAt` IS NULL ORDER BY `name` DESC LIMIT 5' => [[
                'id' => 'product-1',
                'tenantId' => 'tenant-1',
                'name' => 'Product 1',
                'categoryId' => 'category-1',
                'deletedAt' => null,
            ]],
        ]);

        $repository = $this->hydratableRepository($adapter)->forTenant('tenant-1');
        $items = $repository
            ->orderBy($repository->query(), HydratableProductTableModel::column('name'), Direction::Desc)
            ->limit(5)
            ->fetchAllAs(HydratableProductDomainModel::class, $this->hydratableRegistry());

        $this->assertCount(1, $items);
        $this->assertSame('product-1', $items[0]->id);
    }

    #[Test]
    public function system_scope_override_removes_mandatory_tenant_filter(): void
    {
        $adapter = new FakeDatabaseAdapter([
            'SELECT * FROM `hydratable_products` WHERE `deletedAt` IS NULL LIMIT 1' => [[
                'id' => 'product-1',
                'tenantId' => 'tenant-1',
                'name' => 'Product 1',
                'categoryId' => 'category-1',
                'deletedAt' => null,
            ]],
        ]);

        $repository = $this->hydratableRepository($adapter)->withoutTenantScope(SystemScopeToken::issue());
        $item = $repository->query()->limit(1)->fetchOneAs(HydratableProductDomainModel::class, $this->hydratableRegistry());

        $this->assertInstanceOf(HydratableProductDomainModel::class, $item);
        $this->assertSame('SELECT * FROM `hydratable_products` WHERE `deletedAt` IS NULL LIMIT 1', $adapter->executed[0]['sql']);
    }

    #[Test]
    public function insert_update_and_delete_delegate_to_new_write_engine(): void
    {
        $adapter = new FakeDatabaseAdapter([]);
        $repository = $this->persistableRepository($adapter);
        $domainModel = new PersistableProductDomainModel(
            id: 'product-1',
            tenantId: 'tenant-1',
            name: 'Product 1',
            categoryId: 'category-1',
            category: new \Semitexa\Orm\Tests\Fixture\Persistence\PersistableCategoryDomainModel(
                id: 'category-1',
                name: 'Category 1',
            ),
            reviews: [
                new \Semitexa\Orm\Tests\Fixture\Persistence\PersistableReviewDomainModel(
                    id: 'review-1',
                    productId: 'product-1',
                    rating: 5,
                ),
            ],
        );

        $repository->insert($domainModel);
        $repository->update($domainModel);
        $repository->delete($domainModel);

        $this->assertGreaterThanOrEqual(7, count($adapter->executed));
        $this->assertSame('INSERT INTO `products` (`id`, `tenantId`, `name`, `categoryId`, `deletedAt`) VALUES (:id, :tenantId, :name, :categoryId, :deletedAt)', $adapter->executed[0]['sql']);
    }

    private function hydratableRepository(FakeDatabaseAdapter $adapter): DomainRepository
    {
        return new DomainRepository(
            tableModelClass: HydratableProductTableModel::class,
            domainModelClass: HydratableProductDomainModel::class,
            adapter: $adapter,
            mapperRegistry: $this->hydratableRegistry(),
            hydrator: new TableModelHydrator(),
            relationLoader: new TableModelRelationLoader($adapter, new TableModelHydrator()),
        );
    }

    private function persistableRepository(FakeDatabaseAdapter $adapter): DomainRepository
    {
        $registry = new MapperRegistry();
        $registry->build(
            mapperClasses: [PersistableProductMapper::class],
            domainModelClasses: [PersistableProductDomainModel::class],
        );

        return new DomainRepository(
            tableModelClass: ValidProductTableModel::class,
            domainModelClass: PersistableProductDomainModel::class,
            adapter: $adapter,
            mapperRegistry: $registry,
            hydrator: new TableModelHydrator(),
            relationLoader: new TableModelRelationLoader($adapter, new TableModelHydrator()),
        );
    }

    private function hydratableRegistry(): MapperRegistry
    {
        $registry = new MapperRegistry();
        $registry->build(
            mapperClasses: [HydratableProductMapper::class],
            domainModelClasses: [HydratableProductDomainModel::class],
        );

        return $registry;
    }
}

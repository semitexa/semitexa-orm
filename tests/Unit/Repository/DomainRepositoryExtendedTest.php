<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Hydration\ResourceModelHydrator;
use Semitexa\Orm\Hydration\ResourceModelRelationLoader;
use Semitexa\Orm\Mapping\MapperRegistry;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Orm\Tests\Fixture\Hydration\FakeDatabaseAdapter;
use Semitexa\Orm\Tests\Fixture\Hydration\HydratableProductResourceModel;
use Semitexa\Orm\Tests\Fixture\Mapping\HydratableProductDomainModel;
use Semitexa\Orm\Tests\Fixture\Mapping\HydratableProductMapper;

final class DomainRepositoryExtendedTest extends TestCase
{
    #[Test]
    public function count_queries_via_applied_criteria(): void
    {
        $adapter = new FakeDatabaseAdapter([
            'SELECT COUNT(*) AS __c FROM `hydratable_products` WHERE `tenantId` = :tenant_scope AND `deletedAt` IS NULL AND `categoryId` = :w0' => [
                ['__c' => 4],
            ],
        ]);

        $total = $this->repository($adapter)
            ->forTenant('tenant-1')
            ->count(['categoryId' => 'category-1']);

        $this->assertSame(4, $total);
    }

    #[Test]
    public function exists_returns_true_when_any_row_matches(): void
    {
        $adapter = new FakeDatabaseAdapter([
            'SELECT 1 FROM `hydratable_products` WHERE `tenantId` = :tenant_scope AND `deletedAt` IS NULL AND `id` = :w0 LIMIT 1' => [
                ['1' => 1],
            ],
        ]);

        $this->assertTrue(
            $this->repository($adapter)
                ->forTenant('tenant-1')
                ->exists(['id' => 'product-1']),
        );
    }

    #[Test]
    public function find_by_id_or_fail_throws_when_not_found(): void
    {
        $adapter = new FakeDatabaseAdapter([]);

        $this->expectException(\RuntimeException::class);
        $this->repository($adapter)->forTenant('tenant-1')->findByIdOrFail('missing');
    }

    #[Test]
    public function find_by_with_array_criterion_uses_where_in(): void
    {
        $adapter = new FakeDatabaseAdapter([]);

        $this->repository($adapter)
            ->forTenant('tenant-1')
            ->findBy(['categoryId' => ['c1', 'c2']]);

        $sql = $adapter->executed[0]['sql'];
        $this->assertStringContainsString('`categoryId` IN (:in0, :in1)', $sql);
    }

    #[Test]
    public function paginate_executes_count_then_page_select(): void
    {
        $adapter = new FakeDatabaseAdapter([
            'SELECT COUNT(*) AS __c FROM `hydratable_products` WHERE `tenantId` = :tenant_scope AND `deletedAt` IS NULL' => [
                ['__c' => 2],
            ],
            'SELECT * FROM `hydratable_products` WHERE `tenantId` = :tenant_scope AND `deletedAt` IS NULL LIMIT 5 OFFSET 0' => [
                [
                    'id' => 'product-1',
                    'tenantId' => 'tenant-1',
                    'name' => 'Product 1',
                    'categoryId' => 'category-1',
                    'deletedAt' => null,
                ],
            ],
        ]);

        $page = $this->repository($adapter)
            ->forTenant('tenant-1')
            ->paginate(1, 5);

        $this->assertSame(2, $page->total);
        $this->assertSame(1, $page->page);
        $this->assertSame(1, $page->lastPage);
        $this->assertContainsOnlyInstancesOf(HydratableProductDomainModel::class, $page->items);
    }

    private function repository(FakeDatabaseAdapter $adapter): DomainRepository
    {
        $registry = new MapperRegistry();
        $registry->build(
            mapperClasses: [HydratableProductMapper::class],
            domainModelClasses: [HydratableProductDomainModel::class],
        );

        return new DomainRepository(
            resourceModelClass: HydratableProductResourceModel::class,
            domainModelClass: HydratableProductDomainModel::class,
            adapter: $adapter,
            mapperRegistry: $registry,
            hydrator: new ResourceModelHydrator(),
            relationLoader: new ResourceModelRelationLoader($adapter, new ResourceModelHydrator()),
        );
    }
}

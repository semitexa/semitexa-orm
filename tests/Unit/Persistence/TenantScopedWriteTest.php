<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Persistence;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Application\Service\Mapping\MapperRegistry;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\Exception\TenantScopeViolationException;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidProductResourceModel;
use Semitexa\Orm\Tests\Fixture\Persistence\PersistableCategoryDomainModel;
use Semitexa\Orm\Tests\Fixture\Persistence\PersistableProductDomainModel;
use Semitexa\Orm\Tests\Fixture\Persistence\PersistableProductMapper;
use Semitexa\Orm\Tests\Fixture\Persistence\PersistableReviewDomainModel;

final class TenantScopedWriteTest extends TestCase
{
    private OrmManager $orm;
    private DomainRepository $repository;

    protected function setUp(): void
    {
        $this->orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $this->orm->getAdapter()->execute(
            'CREATE TABLE products (id TEXT PRIMARY KEY, tenantId TEXT NOT NULL, name TEXT, categoryId TEXT, deletedAt TEXT)'
        );
        $this->orm->getAdapter()->execute(
            'CREATE TABLE reviews (id TEXT PRIMARY KEY, productId TEXT NOT NULL, rating INTEGER)'
        );

        $registry = new MapperRegistry();
        $registry->build(
            mapperClasses: [PersistableProductMapper::class],
            domainModelClasses: [PersistableProductDomainModel::class],
        );

        $this->repository = new DomainRepository(
            ValidProductResourceModel::class,
            PersistableProductDomainModel::class,
            $this->orm->getAdapter(),
            $registry,
            writeEngine: $this->orm->getAggregateWriteEngine(),
        );
    }

    #[Test]
    public function insert_uses_the_trusted_scope_instead_of_domain_input(): void
    {
        $persisted = $this->repository
            ->forTenant('tenant-a')
            ->insert($this->product(tenantId: 'spoofed'));

        self::assertSame('tenant-a', $persisted->tenantId);
        self::assertSame(
            'tenant-a',
            $this->orm->getAdapter()->query("SELECT tenantId FROM products WHERE id = 'product-1'")->rows[0]['tenantId'],
        );
    }

    #[Test]
    public function cross_tenant_update_is_rejected_before_root_or_children_change(): void
    {
        $this->repository->forTenant('tenant-a')->insert($this->product());

        try {
            $this->repository
                ->forTenant('tenant-b')
                ->update($this->product(tenantId: 'tenant-a', name: 'hacked'));
            self::fail('A cross-tenant update must be rejected.');
        } catch (TenantScopeViolationException) {
            // Missing and foreign targets deliberately have the same shape.
        }

        self::assertSame(
            'Product 1',
            $this->orm->getAdapter()->query("SELECT name FROM products WHERE id = 'product-1'")->rows[0]['name'],
        );
        self::assertSame(
            2,
            (int) $this->orm->getAdapter()->query('SELECT COUNT(*) AS c FROM reviews')->rows[0]['c'],
        );
    }

    #[Test]
    public function scoped_update_keeps_tenant_identity_immutable(): void
    {
        $this->repository->forTenant('tenant-a')->insert($this->product());

        $updated = $this->repository
            ->forTenant('tenant-a')
            ->update($this->product(tenantId: 'spoofed', name: 'Renamed'));

        self::assertSame('tenant-a', $updated->tenantId);
        self::assertSame(
            ['tenantId' => 'tenant-a', 'name' => 'Renamed'],
            $this->orm->getAdapter()->query(
                "SELECT tenantId, name FROM products WHERE id = 'product-1'"
            )->rows[0],
        );
    }

    #[Test]
    public function delete_soft_deletes_the_root_and_preserves_owned_rows(): void
    {
        $this->repository->forTenant('tenant-a')->insert($this->product());

        $this->repository->forTenant('tenant-a')->delete($this->product());

        $row = $this->orm->getAdapter()->query(
            "SELECT deletedAt FROM products WHERE id = 'product-1'"
        )->rows[0];
        self::assertNotNull($row['deletedAt']);
        self::assertNull($this->repository->forTenant('tenant-a')->findById('product-1'));
        self::assertSame(
            2,
            (int) $this->orm->getAdapter()->query('SELECT COUNT(*) AS c FROM reviews')->rows[0]['c'],
        );
    }

    #[Test]
    public function tenant_scoped_write_without_context_fails_closed(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('requires tenant context');

        $this->repository->insert($this->product());
    }

    private function product(
        string $tenantId = 'tenant-a',
        string $name = 'Product 1',
    ): PersistableProductDomainModel {
        return new PersistableProductDomainModel(
            id: 'product-1',
            tenantId: $tenantId,
            name: $name,
            categoryId: 'category-1',
            category: new PersistableCategoryDomainModel(id: 'category-1', name: 'Category 1'),
            reviews: [
                new PersistableReviewDomainModel(id: 'review-1', productId: 'product-1', rating: 5),
                new PersistableReviewDomainModel(id: 'review-2', productId: 'product-1', rating: 4),
            ],
        );
    }
}

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
use Semitexa\Orm\Query\ResourceModelQuery;
use Semitexa\Orm\Query\SystemScopeToken;
use Semitexa\Orm\Tests\Fixture\Hydration\FakeDatabaseAdapter;
use Semitexa\Orm\Tests\Fixture\Hydration\HydratableProductResourceModel;
use Semitexa\Orm\Tests\Fixture\Mapping\HydratableProductDomainModel;
use Semitexa\Orm\Tests\Fixture\Mapping\HydratableProductMapper;

final class ResourceModelQueryExtendedTest extends TestCase
{
    #[Test]
    public function offset_without_limit_still_emits_valid_sql(): void
    {
        $query = $this->query();

        $query->forTenant('tenant-1')->offset(25);

        $this->assertStringContainsString('LIMIT', $query->toSql());
        $this->assertStringContainsString('OFFSET 25', $query->toSql());
    }

    #[Test]
    public function where_in_binds_one_param_per_value(): void
    {
        $query = $this->query();

        $query
            ->forTenant('tenant-1')
            ->whereIn(HydratableProductResourceModel::column('id'), ['a', 'b', 'c']);

        $this->assertSame(
            'SELECT * FROM `hydratable_products` WHERE `tenantId` = :tenant_scope AND `deletedAt` IS NULL AND `id` IN (:in0, :in1, :in2)',
            $query->toSql(),
        );
        $params = $query->toParams();
        $this->assertSame('a', $params['in0']);
        $this->assertSame('b', $params['in1']);
        $this->assertSame('c', $params['in2']);
    }

    #[Test]
    public function where_in_with_empty_values_generates_always_false_guard(): void
    {
        $query = $this->query();

        $query
            ->forTenant('tenant-1')
            ->whereIn(HydratableProductResourceModel::column('id'), []);

        $this->assertStringContainsString('1 = 0', $query->toSql());
    }

    #[Test]
    public function where_not_in_with_empty_values_generates_always_true_guard(): void
    {
        $query = $this->query();

        $query
            ->forTenant('tenant-1')
            ->whereNotIn(HydratableProductResourceModel::column('id'), []);

        $this->assertStringContainsString('1 = 1', $query->toSql());
    }

    #[Test]
    public function where_between_emits_typed_boundaries(): void
    {
        $query = $this->query();

        $query
            ->forTenant('tenant-1')
            ->whereBetween(HydratableProductResourceModel::column('name'), 'a', 'm');

        $sql = $query->toSql();
        $this->assertStringContainsString('BETWEEN :w0 AND :w1', $sql);
        $params = $query->toParams();
        $this->assertSame('a', $params['w0']);
        $this->assertSame('m', $params['w1']);
    }

    #[Test]
    public function where_like_and_not_like_use_shared_operator_surface(): void
    {
        $query = $this->query();

        $query
            ->forTenant('tenant-1')
            ->whereLike(HydratableProductResourceModel::column('name'), '%Pro%')
            ->whereNotLike(HydratableProductResourceModel::column('name'), '%junk%');

        $sql = $query->toSql();
        $this->assertStringContainsString('`name` LIKE :w0', $sql);
        $this->assertStringContainsString('`name` NOT LIKE :w1', $sql);
    }

    #[Test]
    public function or_where_switches_connector_without_breaking_scopes(): void
    {
        $query = $this->query();

        $query
            ->forTenant('tenant-1')
            ->where(HydratableProductResourceModel::column('name'), Operator::Equals, 'A')
            ->orWhere(HydratableProductResourceModel::column('name'), Operator::Equals, 'B');

        $sql = $query->toSql();
        $this->assertStringContainsString('AND `name` = :w0 OR `name` = :w1', $sql);
    }

    #[Test]
    public function where_raw_supports_positional_placeholders(): void
    {
        $query = $this->query();

        $query
            ->forTenant('tenant-1')
            ->whereRaw('JSON_EXTRACT(`name`, ?) = ?', ['$.type', 'pro']);

        $sql = $query->toSql();
        $this->assertMatchesRegularExpression(
            '/\(JSON_EXTRACT\(`name`, :raw\d+\) = :raw\d+\)/',
            $sql,
        );
        $params = $query->toParams();
        $this->assertContains('$.type', $params);
        $this->assertContains('pro', $params);
    }

    #[Test]
    public function fetch_one_does_not_mutate_original_limit(): void
    {
        $adapter = new FakeDatabaseAdapter([]);
        $query = $this->query($adapter);

        $query
            ->forTenant('tenant-1')
            ->limit(50);

        $query->fetchOne();
        $query->fetchAll();

        $this->assertSame(2, count($adapter->executed));
        $this->assertStringContainsString('LIMIT 1', $adapter->executed[0]['sql']);
        $this->assertStringContainsString('LIMIT 50', $adapter->executed[1]['sql']);
    }

    #[Test]
    public function count_ignores_limit_and_order_by_and_returns_int(): void
    {
        $adapter = new FakeDatabaseAdapter([
            'SELECT COUNT(*) AS __c FROM `hydratable_products` WHERE `tenantId` = :tenant_scope AND `deletedAt` IS NULL' => [
                ['__c' => 7],
            ],
        ]);
        $query = $this->query($adapter);

        $total = $query
            ->forTenant('tenant-1')
            ->limit(3)
            ->orderBy(HydratableProductResourceModel::column('name'), Direction::Asc)
            ->count();

        $this->assertSame(7, $total);
    }

    #[Test]
    public function exists_uses_cheap_limit_probe(): void
    {
        $adapter = new FakeDatabaseAdapter([
            'SELECT 1 FROM `hydratable_products` WHERE `tenantId` = :tenant_scope AND `deletedAt` IS NULL LIMIT 1' => [
                ['1' => 1],
            ],
        ]);
        $query = $this->query($adapter);

        $this->assertTrue($query->forTenant('tenant-1')->exists());
    }

    #[Test]
    public function paginate_emits_count_then_select_with_offset(): void
    {
        $adapter = new FakeDatabaseAdapter([
            'SELECT COUNT(*) AS __c FROM `hydratable_products` WHERE `tenantId` = :tenant_scope AND `deletedAt` IS NULL' => [
                ['__c' => 42],
            ],
            'SELECT * FROM `hydratable_products` WHERE `tenantId` = :tenant_scope AND `deletedAt` IS NULL LIMIT 10 OFFSET 20' => [
                [
                    'id' => 'product-21',
                    'tenantId' => 'tenant-1',
                    'name' => 'Product 21',
                    'categoryId' => 'category-1',
                    'deletedAt' => null,
                ],
            ],
        ]);

        $query = $this->query($adapter);
        $page = $query->forTenant('tenant-1')->paginate(3, 10);

        $this->assertSame(42, $page->total);
        $this->assertSame(3, $page->page);
        $this->assertSame(10, $page->perPage);
        $this->assertSame(5, $page->lastPage);
        $this->assertCount(1, $page->items);
    }

    #[Test]
    public function paginate_as_maps_into_domain_models(): void
    {
        $adapter = new FakeDatabaseAdapter([
            'SELECT COUNT(*) AS __c FROM `hydratable_products` WHERE `tenantId` = :tenant_scope AND `deletedAt` IS NULL' => [
                ['__c' => 1],
            ],
            'SELECT * FROM `hydratable_products` WHERE `tenantId` = :tenant_scope AND `deletedAt` IS NULL LIMIT 10 OFFSET 0' => [
                [
                    'id' => 'product-1',
                    'tenantId' => 'tenant-1',
                    'name' => 'Product 1',
                    'categoryId' => 'category-1',
                    'deletedAt' => null,
                ],
            ],
        ]);

        $registry = new MapperRegistry();
        $registry->build(
            mapperClasses: [HydratableProductMapper::class],
            domainModelClasses: [HydratableProductDomainModel::class],
        );

        $query = $this->query($adapter);
        $page = $query
            ->forTenant('tenant-1')
            ->paginateAs(1, 10, HydratableProductDomainModel::class, $registry);

        $this->assertContainsOnlyInstancesOf(HydratableProductDomainModel::class, $page->items);
    }

    #[Test]
    public function to_debug_sql_interpolates_params(): void
    {
        $query = $this->query();
        $query
            ->forTenant('tenant-1')
            ->where(HydratableProductResourceModel::column('name'), Operator::Equals, "O'Neil");

        $debug = $query->toDebugSql();
        $this->assertStringContainsString("'tenant-1'", $debug);
        $this->assertStringContainsString("'O''Neil'", $debug);
        $this->assertStringNotContainsString(':tenant_scope', $debug);
    }

    #[Test]
    public function system_scope_token_skips_tenant_predicate(): void
    {
        $query = $this->query();
        $query->withoutTenantScope(SystemScopeToken::issue());

        $this->assertStringNotContainsString(':tenant_scope', $query->toSql());
    }

    private function query(?FakeDatabaseAdapter $adapter = null): ResourceModelQuery
    {
        $adapter ??= new FakeDatabaseAdapter([]);
        $hydrator = new ResourceModelHydrator();
        $relationLoader = new ResourceModelRelationLoader($adapter, $hydrator);

        return new ResourceModelQuery(
            HydratableProductResourceModel::class,
            $adapter,
            $hydrator,
            $relationLoader,
        );
    }
}

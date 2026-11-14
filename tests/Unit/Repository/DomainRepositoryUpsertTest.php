<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Application\Service\Mapping\MapperRegistry;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\Metadata\ColumnRef;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Orm\Tests\Fixture\Metadata\DirectTenantColumnResourceModel;
use Semitexa\Orm\Tests\Fixture\Upsert\UpsertSetting;
use Semitexa\Orm\Tests\Fixture\Upsert\UpsertSettingMapper;
use Semitexa\Orm\Tests\Fixture\Upsert\UpsertSettingResource;

/**
 * DomainRepository::upsert() (orm-gap-1-repository-upsert): a consumer project wrote seven raw
 * `INSERT ... ON DUPLICATE KEY` statements for settings-like rows keyed by a natural key.
 * Exercised on real SQLite through the engine's transactional path.
 */
final class DomainRepositoryUpsertTest extends TestCase
{
    private OrmManager $orm;

    private DomainRepository $repository;

    protected function setUp(): void
    {
        $this->orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $this->orm->getAdapter()->execute(
            'CREATE TABLE upsert_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id TEXT NOT NULL, name TEXT NOT NULL,'
            . ' value TEXT, created_at TEXT, version INTEGER, UNIQUE (tenant_id, name))',
        );
        $registry = new MapperRegistry();
        $registry->build(mapperClasses: [UpsertSettingMapper::class], domainModelClasses: [UpsertSetting::class]);
        $this->repository = new DomainRepository(
            UpsertSettingResource::class,
            UpsertSetting::class,
            $this->orm->getAdapter(),
            $registry,
            writeEngine: $this->orm->getAggregateWriteEngine(),
        );
    }

    #[Test]
    public function the_first_upsert_inserts_and_returns_the_stored_row(): void
    {
        $stored = $this->repository->forTenant('t1')->upsert($this->setting('theme', 'dark', '2026-01-01'), UpsertSettingResource::column('name'));

        self::assertInstanceOf(UpsertSetting::class, $stored);
        self::assertGreaterThan(0, $stored->id, 'the id the database assigned');
        self::assertSame(['t1', 'theme', 'dark', 1], [$stored->tenantId, $stored->name, $stored->value, $stored->version]);
        self::assertSame(1, $this->rows());
    }

    #[Test]
    public function the_next_upsert_on_the_key_updates_that_row_and_keeps_what_it_was_given_once(): void
    {
        $first = $this->repository->forTenant('t1')->upsert($this->setting('theme', 'dark', '2026-01-01'), UpsertSettingResource::column('name'));
        $second = $this->repository->forTenant('t1')->upsert($this->setting('theme', 'light', '2026-02-02'), UpsertSettingResource::column('name'));

        self::assertInstanceOf(UpsertSetting::class, $second);
        self::assertSame(1, $this->rows(), 'one row per key');
        self::assertSame($first->id, $second->id, 'the same row');
        self::assertSame('light', $second->value);
        self::assertSame('2026-01-01', $second->createdAt, 'created_at is the first write\'s');
        self::assertSame(2, $second->version, 'a version is bumped, so a stale update() holder is refused');
    }

    #[Test]
    public function the_tenant_is_part_of_the_key(): void
    {
        $this->repository->forTenant('t1')->upsert($this->setting('theme', 'dark', '2026-01-01'), UpsertSettingResource::column('name'));
        $this->repository->forTenant('t2')->upsert($this->setting('theme', 'light', '2026-01-01'), UpsertSettingResource::column('name'));

        self::assertSame(2, $this->rows(), 'another tenant\'s row with the same name is never touched');
        self::assertSame(
            [['tenant_id' => 't1', 'value' => 'dark'], ['tenant_id' => 't2', 'value' => 'light']],
            $this->orm->getAdapter()->query('SELECT tenant_id, value FROM upsert_settings ORDER BY tenant_id')->rows,
        );
    }

    #[Test]
    public function a_key_that_is_no_unique_index_is_refused_before_anything_is_written(): void
    {
        try {
            $this->repository->forTenant('t1')->upsert($this->setting('theme', 'dark', '2026-01-01'), UpsertSettingResource::column('value'));
            self::fail('a key with no unique index would only ever insert');
        } catch (\LogicException $e) {
            self::assertStringContainsString('is no #[Index(unique: true)] it declares', $e->getMessage());
        }
        self::assertSame(0, $this->rows());
    }

    #[Test]
    public function the_primary_key_is_not_an_upsert_key(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('the key is the primary key');
        $this->repository->forTenant('t1')->upsert($this->setting('theme', 'dark', '2026-01-01'), UpsertSettingResource::column('id'));
    }

    #[Test]
    public function a_key_of_another_model_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('belongs to ' . DirectTenantColumnResourceModel::class);
        $this->repository->forTenant('t1')->upsert($this->setting('theme', 'dark', '2026-01-01'), ColumnRef::for(DirectTenantColumnResourceModel::class, 'tenantId'));
    }

    private function setting(string $name, string $value, string $createdAt): UpsertSetting
    {
        return new UpsertSetting(0, '', $name, $value, $createdAt, 1);
    }

    private function rows(): int
    {
        return (int) $this->orm->getAdapter()->query('SELECT COUNT(*) AS c FROM upsert_settings')->rows[0]['c'];
    }
}

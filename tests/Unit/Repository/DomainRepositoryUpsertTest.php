<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Application\Service\Mapping\MapperRegistry;
use Semitexa\Orm\Application\Service\Persistence\AggregateUpsert;
use Semitexa\Orm\Metadata\ResourceModelMetadataRegistry;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\Metadata\ColumnRef;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Orm\Tests\Fixture\Metadata\DirectTenantColumnResourceModel;
use Semitexa\Orm\Tests\Fixture\Query\BookedNightResourceModel;
use Semitexa\Orm\Tests\Fixture\Upsert\UpsertSetting;
use Semitexa\Orm\Tests\Fixture\Upsert\UpsertSettingMapper;
use Semitexa\Orm\Tests\Fixture\Upsert\MixedIndexSettingResource;
use Semitexa\Orm\Tests\Fixture\Upsert\TwoUniqueSetting;
use Semitexa\Orm\Tests\Fixture\Upsert\TwoUniqueSettingMapper;
use Semitexa\Orm\Tests\Fixture\Upsert\TwoUniqueSettingResource;
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

    /** Review, orm#110: on MySQL a given id collides on the primary key, another tenant's row included. */
    #[Test]
    public function the_callers_id_is_never_sent_the_key_decides_the_row(): void
    {
        $t1 = $this->repository->forTenant('t1')->upsert(new UpsertSetting(999, '', 'theme', 'dark', '2026-01-01', 1), UpsertSettingResource::column('name'));
        self::assertInstanceOf(UpsertSetting::class, $t1);
        self::assertNotSame(999, $t1->id, 'the database gave the id');

        // Another tenant passing t1's id gets a row of its own; t1's is untouched.
        $this->repository->forTenant('t2')->upsert(new UpsertSetting($t1->id, '', 'other', 'changed', '2026-01-01', 1), UpsertSettingResource::column('name'));
        self::assertSame(2, $this->rows());
        self::assertSame(
            [['tenant_id' => 't1', 'value' => 'dark', 'version' => 1]],
            array_map(static fn (array $r): array => ['tenant_id' => $r['tenant_id'], 'value' => $r['value'], 'version' => (int) $r['version']], $this->orm->getAdapter()->query("SELECT tenant_id, value, version FROM upsert_settings WHERE id = {$t1->id}")->rows),
        );
    }

    /**
     * Fable's review, orm#110: the row is UPDATEd by its key, so a second unique index can only
     * make the write fail, never redirect it to the row that index matches.
     */
    #[Test]
    public function another_unique_index_can_fail_the_write_but_never_redirect_it(): void
    {
        $this->orm->getAdapter()->execute('CREATE TABLE two_unique_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, slug TEXT NOT NULL UNIQUE)');
        $registry = new MapperRegistry();
        $registry->build(mapperClasses: [TwoUniqueSettingMapper::class], domainModelClasses: [TwoUniqueSetting::class]);
        $repository = new DomainRepository(TwoUniqueSettingResource::class, TwoUniqueSetting::class, $this->orm->getAdapter(), $registry, writeEngine: $this->orm->getAggregateWriteEngine());

        $repository->upsert(new TwoUniqueSetting(0, 'a', 's1'), TwoUniqueSettingResource::column('name'));
        $repository->upsert(new TwoUniqueSetting(0, 'a', 's2'), TwoUniqueSettingResource::column('name'));
        $repository->upsert(new TwoUniqueSetting(0, 'b', 's3'), TwoUniqueSettingResource::column('name'));
        $before = $this->orm->getAdapter()->query('SELECT name, slug FROM two_unique_settings ORDER BY name')->rows;
        self::assertSame([['name' => 'a', 'slug' => 's2'], ['name' => 'b', 'slug' => 's3']], $before);

        // Only the upsert's own exception is caught: a fail() inside the try would be swallowed (review, orm#110).
        $refused = null;
        try {
            $repository->upsert(new TwoUniqueSetting(0, 'a', 's3'), TwoUniqueSettingResource::column('name'));
        } catch (\Throwable $e) {
            $refused = $e; // the UPDATE of a's row violates the slug index
        }
        self::assertNotNull($refused, 'a slug that is b\'s must fail the write, not move it to b');
        self::assertSame($before, $this->orm->getAdapter()->query('SELECT name, slug FROM two_unique_settings ORDER BY name')->rows, 'both rows as they were');
    }

    /** Review, orm#112: a unique index with a non-string column is no index, so its other columns are no key. */
    #[Test]
    public function a_unique_index_with_a_column_that_is_not_a_string_is_not_declared(): void
    {
        self::assertSame([], ResourceModelMetadataRegistry::default()->for(MixedIndexSettingResource::class)->uniqueIndexes);
    }

    /** Fable's review, orm#110: an id the caller assigns could be another tenant's row's. */
    #[Test]
    public function a_primary_key_the_caller_assigns_is_refused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('its primary key is assigned by the caller (manual)');
        AggregateUpsert::conflictColumns(ResourceModelMetadataRegistry::default()->for(BookedNightResourceModel::class), ['room'], null);
    }

    /** Review, orm#110: a null key never collides (unique indexes let NULLs repeat), and `= NULL` finds nothing. */
    #[Test]
    public function a_null_key_value_is_refused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('the key column name is null; a null key names no row');
        AggregateUpsert::write(
            $this->orm->getAdapter(),
            ResourceModelMetadataRegistry::default()->for(UpsertSettingResource::class),
            ['tenant_id' => 't1', 'name' => null, 'value' => 'v'],
            ['tenant_id', 'name'],
        );
    }

    /** Review, orm#110: the read-back refuses a wrong collision, and only a transaction undoes the write. */
    #[Test]
    public function an_engine_with_no_transaction_is_refused_before_it_writes(): void
    {
        $registry = new MapperRegistry();
        $registry->build(mapperClasses: [UpsertSettingMapper::class], domainModelClasses: [UpsertSetting::class]);
        $bare = new DomainRepository(UpsertSettingResource::class, UpsertSetting::class, $this->orm->getAdapter(), $registry);

        try {
            $bare->forTenant('t1')->upsert($this->setting('theme', 'dark', '2026-01-01'), UpsertSettingResource::column('name'));
            self::fail('no transaction, no upsert');
        } catch (\LogicException $e) {
            self::assertStringContainsString('needs a transaction manager', $e->getMessage());
        }
        self::assertSame(0, $this->rows());
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

<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Integration\Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Application\Service\Mapping\MapperRegistry;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Orm\Tests\Fixture\Upsert\UpsertSetting;
use Semitexa\Orm\Tests\Fixture\Upsert\UpsertSettingMapper;
use Semitexa\Orm\Tests\Fixture\Upsert\UpsertSettingResource;
use Semitexa\Orm\Tests\Support\RequiresDatabase;

/**
 * On MySQL an upsert locks the row by its key, then UPDATEs or INSERTs it, and reads it back
 * (orm-gap-1-repository-upsert). No ON DUPLICATE KEY: it fires on whichever unique key
 * collides first, an undeclared one included, and updates that row (Fable's review, orm#110).
 */
final class RepositoryUpsertMysqlTest extends TestCase
{
    use RequiresDatabase;

    private OrmManager $orm;

    protected function setUp(): void
    {
        $this->orm = self::mysqlOrm('ON DUPLICATE KEY UPDATE is MySQL\'s');
        $this->orm->getAdapter()->execute('DROP TABLE IF EXISTS upsert_settings');
        $this->orm->getAdapter()->execute(
            'CREATE TABLE upsert_settings (id INT AUTO_INCREMENT PRIMARY KEY, tenant_id VARCHAR(64) NOT NULL, name VARCHAR(64) NOT NULL,'
            . ' value VARCHAR(255), created_at VARCHAR(32), version INT, UNIQUE KEY uniq_tenant_name (tenant_id, name)) ENGINE=InnoDB',
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->orm)) {
            try {
                $this->orm->getAdapter()->execute('DROP TABLE IF EXISTS upsert_settings');
            } finally {
                $this->orm->shutdown();
            }
        }
    }

    /** Fable's review, orm#110: a unique index the model never declared cannot redirect the write. */
    #[Test]
    public function an_undeclared_unique_index_fails_the_write_and_touches_no_other_tenants_row(): void
    {
        // Created BEFORE the declared (tenant_id, name) index, as a migration might, on a NOT NULL
        // column (InnoDB checks NOT NULL unique keys in creation order): ON DUPLICATE KEY updated
        // t2's row here, and the read-back by (t1, x) found t1's untouched. This test was red on it.
        $this->orm->getAdapter()->execute('DROP TABLE upsert_settings');
        $this->orm->getAdapter()->execute(
            'CREATE TABLE upsert_settings (id INT AUTO_INCREMENT PRIMARY KEY, tenant_id VARCHAR(64) NOT NULL, name VARCHAR(64) NOT NULL,'
            . ' value VARCHAR(255) NOT NULL, created_at VARCHAR(32), version INT, UNIQUE KEY uniq_value (value), UNIQUE KEY uniq_tenant_name (tenant_id, name)) ENGINE=InnoDB',
        );
        $this->orm->getAdapter()->execute("INSERT INTO upsert_settings (tenant_id, name, value, created_at, version) VALUES ('t1', 'x', 'a', '2026-01-01', 1), ('t2', 'y', 'b', '2026-01-01', 1)");
        $before = $this->orm->getAdapter()->query('SELECT tenant_id, name, value, version FROM upsert_settings ORDER BY tenant_id')->rows;

        try {
            $this->repository()->upsert(new UpsertSetting(0, '', 'x', 'b', '2026-02-02', 1), UpsertSettingResource::column('name'));
            self::fail("t1's write of t2's value must fail, not update t2's row");
        } catch (\Throwable) {
            // duplicate entry on uniq_value; the transaction rolls back
        }
        self::assertSame($before, $this->orm->getAdapter()->query('SELECT tenant_id, name, value, version FROM upsert_settings ORDER BY tenant_id')->rows);
    }

    private function repository(): DomainRepository
    {
        $registry = new MapperRegistry();
        $registry->build(mapperClasses: [UpsertSettingMapper::class], domainModelClasses: [UpsertSetting::class]);

        return (new DomainRepository(UpsertSettingResource::class, UpsertSetting::class, $this->orm->getAdapter(), $registry, writeEngine: $this->orm->getAggregateWriteEngine()))->forTenant('t1');
    }

    #[Test]
    public function the_second_upsert_updates_the_row_and_the_stored_id_comes_back(): void
    {
        $registry = new MapperRegistry();
        $registry->build(mapperClasses: [UpsertSettingMapper::class], domainModelClasses: [UpsertSetting::class]);
        $repository = (new DomainRepository(
            UpsertSettingResource::class,
            UpsertSetting::class,
            $this->orm->getAdapter(),
            $registry,
            writeEngine: $this->orm->getAggregateWriteEngine(),
        ))->forTenant('t1');

        $first = $repository->upsert(new UpsertSetting(0, '', 'theme', 'dark', '2026-01-01', 1), UpsertSettingResource::column('name'));
        $second = $repository->upsert(new UpsertSetting(0, '', 'theme', 'light', '2026-02-02', 1), UpsertSettingResource::column('name'));

        self::assertInstanceOf(UpsertSetting::class, $first);
        self::assertInstanceOf(UpsertSetting::class, $second);
        self::assertGreaterThan(0, $first->id);
        self::assertSame($first->id, $second->id, 'the update path returns the existing id, not 0');
        self::assertSame(['light', '2026-01-01', 2], [$second->value, $second->createdAt, $second->version]);
        self::assertSame(1, (int) $this->orm->getAdapter()->query('SELECT COUNT(*) AS c FROM upsert_settings')->rows[0]['c']);
    }
}

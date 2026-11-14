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
 * On MySQL an upsert is INSERT ... ON DUPLICATE KEY UPDATE, and the row is read back by its
 * key: LAST_INSERT_ID says nothing on the update path (orm-gap-1-repository-upsert).
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

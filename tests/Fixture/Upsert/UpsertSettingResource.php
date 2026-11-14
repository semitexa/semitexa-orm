<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Upsert;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Attribute\TenantScoped;
use Semitexa\Orm\Attribute\Version;

/** A settings-like row keyed by its name per tenant, as the consumer project's raw upserts are. */
#[FromTable(name: 'upsert_settings')]
#[TenantScoped(strategy: 'column', column: 'tenant_id')]
#[Index(columns: ['tenant_id', 'name'], unique: true)]
#[Index(columns: ['value'])]
final readonly class UpsertSettingResource
{
    use \Semitexa\Orm\Metadata\HasColumnReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'auto')]
        #[Column(type: MySqlType::Int)]
        public int $id,

        #[Column(name: 'tenant_id', type: MySqlType::Varchar, length: 64)]
        public string $tenantId,

        #[Column(type: MySqlType::Varchar, length: 64)]
        public string $name,

        #[Column(type: MySqlType::Varchar, length: 255)]
        public string $value,

        #[Column(name: 'created_at', type: MySqlType::Varchar, length: 32)]
        public string $createdAt,

        #[Version]
        #[Column(type: MySqlType::Int)]
        public int $version,
    ) {}
}

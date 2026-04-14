<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Metadata;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Attribute\TenantScoped;

#[FromTable(name: 'mapped_tenant_property_models')]
#[TenantScoped(strategy: 'column', column: 'tenantId')]
final readonly class MappedTenantPropertyResourceModel
{
    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,

        #[Column(name: 'tenant_id', type: MySqlType::Varchar, length: 64)]
        public string $tenantId,
    ) {}
}

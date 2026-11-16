<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\BulkDelete;

use DateTimeImmutable;
use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Attribute\SoftDelete;
use Semitexa\Orm\Attribute\TenantScoped;
use Semitexa\Orm\Attribute\Version;
use Semitexa\Orm\Metadata\HasColumnReferences;

/** A tenant-scoped, soft-deleted, versioned model with no owned relations: what deleteWhere() takes whole. */
#[FromTable(name: 'bulk_sessions')]
#[TenantScoped(strategy: 'column', column: 'tenantId')]
#[SoftDelete(column: 'deletedAt')]
final readonly class BulkSessionResourceModel
{
    use HasColumnReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'manual')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,

        #[Column(type: MySqlType::Varchar, length: 64)]
        public string $tenantId,

        #[Column(type: MySqlType::Varchar, length: 32)]
        public string $state,

        #[Version]
        #[Column(type: MySqlType::Int)]
        public int $version = 1,

        #[Column(type: MySqlType::Datetime, nullable: true)]
        public ?DateTimeImmutable $deletedAt = null,
    ) {}
}

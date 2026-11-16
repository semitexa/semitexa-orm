<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\BulkDelete;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Metadata\HasColumnReferences;

/** No tenant, no soft delete: deleteWhere() removes its rows outright. */
#[FromTable(name: 'bulk_logs')]
final readonly class BulkLogResourceModel
{
    use HasColumnReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'manual')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,

        #[Column(type: MySqlType::Varchar, length: 16)]
        public string $level,
    ) {}
}

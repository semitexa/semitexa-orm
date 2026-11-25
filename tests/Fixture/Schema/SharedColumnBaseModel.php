<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Schema;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;

/** The first resource on a shared table: `status` without #[Filterable] (SchemaCollectorSharedColumnIndexTest). */
#[FromTable(name: 'shared_column_items')]
final readonly class SharedColumnBaseModel
{
    public function __construct(
        #[PrimaryKey]
        #[Column(type: MySqlType::Int)]
        public int $id,
        #[Column(type: MySqlType::Varchar, length: 32)]
        public string $status,
    ) {}
}

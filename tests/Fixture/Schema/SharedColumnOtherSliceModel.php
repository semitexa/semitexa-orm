<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Schema;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\Filterable;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;

/** A second slice of the same table that also filters by `status` (SchemaCollectorSharedColumnIndexTest). */
#[FromTable(name: 'shared_column_items')]
final readonly class SharedColumnOtherSliceModel
{
    public function __construct(
        #[PrimaryKey]
        #[Column(type: MySqlType::Int)]
        public int $id,
        #[Filterable]
        #[Column(type: MySqlType::Varchar, length: 32)]
        public string $status,
    ) {}
}

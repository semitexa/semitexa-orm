<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixtures\Query;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;

/** Another table: a ColumnRef of it is refused by a query or an aggregate of RankedLeadFixture. */
#[FromTable(name: 'other_table')]
final readonly class OtherTableFixture
{
    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,

        #[Column(type: MySqlType::Int)]
        public int $amount,
    ) {}
}

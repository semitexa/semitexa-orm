<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Schema;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;

/** The table an order belongs to (SchemaCollectorRelationsTest). */
#[FromTable(name: 'relation_customers')]
final readonly class RelationCustomerModel
{
    public function __construct(
        #[PrimaryKey]
        #[Column(type: MySqlType::Int)]
        public int $id,
    ) {}
}

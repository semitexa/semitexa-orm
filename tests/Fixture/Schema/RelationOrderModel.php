<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Schema;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\BelongsTo;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Domain\Enum\ForeignKeyAction;

/** An order whose customer relation asks for its foreign-key actions (SchemaCollectorRelationsTest). */
#[FromTable(name: 'relation_orders')]
final readonly class RelationOrderModel
{
    public function __construct(
        #[PrimaryKey]
        #[Column(type: MySqlType::Int)]
        public int $id,
        #[Column(type: MySqlType::Int)]
        public int $customer_id,
        #[BelongsTo(target: RelationCustomerModel::class, foreignKey: 'customer_id', onDelete: ForeignKeyAction::Cascade, onUpdate: ForeignKeyAction::NoAction)]
        public ?RelationCustomerModel $customer = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Query;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Metadata\HasColumnReferences;

/** A night booked in a room: the row a check-then-book locks (forUpdate()). */
#[FromTable(name: 'orm_test_booked_nights')]
final readonly class BookedNightResourceModel
{
    use HasColumnReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'manual')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,
        #[Column(type: MySqlType::Varchar, length: 32)]
        public string $room,
        #[Column(type: MySqlType::Varchar, length: 10)]
        public string $night,
    ) {}
}

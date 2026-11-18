<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixtures\Query;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;

/** The leads OrderByValuesAndAggregateTest orders and groups. */
#[FromTable(name: 'ranked_leads')]
final readonly class RankedLeadFixture
{
    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,

        #[Column(type: MySqlType::Varchar, length: 32, nullable: true)]
        public ?string $status,

        #[Column(type: MySqlType::Varchar, length: 32)]
        public string $region,

        #[Column(type: MySqlType::Int)]
        public int $amount,

        #[Column(type: MySqlType::Int)]
        public int $created,
    ) {}
}

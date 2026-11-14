<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Upsert;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Attribute\PrimaryKey;

/** A unique index with a column that is not a string: no index is created, so none is declared. */
#[FromTable(name: 'mixed_index_settings')]
#[Index(columns: ['name', 42], unique: true)]
final readonly class MixedIndexSettingResource
{
    public function __construct(
        #[PrimaryKey(strategy: 'auto')]
        #[Column(type: MySqlType::Int)]
        public int $id,

        #[Column(type: MySqlType::Varchar, length: 64)]
        public string $name,
    ) {}
}

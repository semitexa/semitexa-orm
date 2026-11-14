<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Upsert;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Attribute\PrimaryKey;

/** Two unique indexes: MySQL's ON DUPLICATE KEY could fire on either, so upsert refuses it. */
#[FromTable(name: 'two_unique_settings')]
#[Index(columns: ['name'], unique: true)]
#[Index(columns: ['slug'], unique: true)]
final readonly class TwoUniqueSettingResource
{
    use \Semitexa\Orm\Metadata\HasColumnReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'auto')]
        #[Column(type: MySqlType::Int)]
        public int $id,

        #[Column(type: MySqlType::Varchar, length: 64)]
        public string $name,

        #[Column(type: MySqlType::Varchar, length: 64)]
        public string $slug,
    ) {}
}

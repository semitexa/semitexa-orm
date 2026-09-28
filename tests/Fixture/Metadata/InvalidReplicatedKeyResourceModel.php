<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Metadata;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Attribute\Replicated;

/** #[Replicated] with an auto-increment key — must be refused. */
#[FromTable(name: 'replicated_counters')]
#[Replicated]
final readonly class InvalidReplicatedKeyResourceModel
{
    public function __construct(
        #[PrimaryKey(strategy: 'auto')]
        #[Column(type: MySqlType::Int)]
        public int $id,
    ) {}
}

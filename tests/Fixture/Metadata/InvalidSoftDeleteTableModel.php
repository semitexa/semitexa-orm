<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Metadata;

use DateTimeImmutable;
use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Attribute\SoftDelete;

#[FromTable(name: 'invalid_soft_delete_models')]
#[SoftDelete(column: 'deletedAt')]
final readonly class InvalidSoftDeleteTableModel
{
    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,

        #[Column(type: MySqlType::Datetime)]
        public DateTimeImmutable $deletedAt,
    ) {}
}

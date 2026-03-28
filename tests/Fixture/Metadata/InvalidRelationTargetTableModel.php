<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Metadata;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\BelongsTo;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Persistence\RelationWritePolicy;

#[FromTable(name: 'invalid_relation_target_models')]
final readonly class InvalidRelationTargetTableModel
{
    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,

        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $missingId,

        #[BelongsTo(
            target: 'Semitexa\\Orm\\Tests\\Fixture\\Metadata\\MissingTableModel',
            foreignKey: 'missingId',
            writePolicy: RelationWritePolicy::ReferenceOnly,
        )]
        public mixed $missing = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Metadata;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\Connection;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Attribute\Replicated;
use Semitexa\Orm\Metadata\HasColumnReferences;
use Semitexa\Orm\Metadata\HasRelationReferences;

/** The same table name as ReplicatedNoteResourceModel, in another database. */
#[FromTable(name: 'replicated_notes')]
#[Connection('archive')]
#[Replicated]
final readonly class ArchivedReplicatedNoteResourceModel
{
    use HasColumnReferences;
    use HasRelationReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,

        #[Column(type: MySqlType::Varchar, length: 255)]
        public string $title,
    ) {}
}

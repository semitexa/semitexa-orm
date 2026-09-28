<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Metadata;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\HasMany;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Domain\Enum\RelationWritePolicy;

/** CascadeOwned children that are #[Replicated] — must be refused. */
#[FromTable(name: 'notebooks')]
final readonly class InvalidCascadeToReplicatedResourceModel
{
    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,

        #[HasMany(
            target: ReplicatedNoteResourceModel::class,
            foreignKey: 'notebookId',
            writePolicy: RelationWritePolicy::CascadeOwned,
        )]
        public array $notes = [],
    ) {}
}

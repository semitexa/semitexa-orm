<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Metadata;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\BelongsTo;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Metadata\HasColumnReferences;
use Semitexa\Orm\Metadata\HasRelationReferences;
use Semitexa\Orm\Persistence\RelationWritePolicy;

#[FromTable(name: 'invalid_sync_pivot_products')]
final readonly class InvalidSyncPivotBelongsToResourceModel
{
    use HasColumnReferences;
    use HasRelationReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,

        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $categoryId,

        #[BelongsTo(
            target: ValidCategoryResourceModel::class,
            foreignKey: 'categoryId',
            writePolicy: RelationWritePolicy::SyncPivotOnly,
        )]
        public ?ValidCategoryResourceModel $category = null,
    ) {}
}

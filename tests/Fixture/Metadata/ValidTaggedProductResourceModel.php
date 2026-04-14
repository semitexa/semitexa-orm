<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Metadata;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\ManyToMany;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Metadata\HasColumnReferences;
use Semitexa\Orm\Metadata\HasRelationReferences;
use Semitexa\Orm\Persistence\RelationWritePolicy;

#[FromTable(name: 'tagged_products')]
final readonly class ValidTaggedProductResourceModel
{
    use HasColumnReferences;
    use HasRelationReferences;

    /**
     * @param list<ValidTagResourceModel|string> $tags
     */
    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,

        #[Column(type: MySqlType::Varchar, length: 255)]
        public string $name,

        #[ManyToMany(
            target: ValidTagResourceModel::class,
            pivotTable: 'product_tags',
            foreignKey: 'productId',
            relatedKey: 'tagId',
            writePolicy: RelationWritePolicy::SyncPivotOnly,
        )]
        public array $tags = [],
    ) {}
}

<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Hydration;

use DateTimeImmutable;
use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\BelongsTo;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\HasMany;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Attribute\SoftDelete;
use Semitexa\Orm\Attribute\TenantScoped;
use Semitexa\Orm\Hydration\RelationState;
use Semitexa\Orm\Metadata\HasColumnReferences;
use Semitexa\Orm\Metadata\HasRelationReferences;
use Semitexa\Orm\Persistence\RelationWritePolicy;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidCategoryTableModel;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidReviewTableModel;

#[FromTable(name: 'hydratable_products')]
#[TenantScoped(strategy: 'column', column: 'tenantId')]
#[SoftDelete(column: 'deletedAt')]
final readonly class HydratableProductTableModel
{
    use HasColumnReferences;
    use HasRelationReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,

        #[Column(type: MySqlType::Varchar, length: 64)]
        public string $tenantId,

        #[Column(type: MySqlType::Varchar, length: 255)]
        public string $name,

        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $categoryId,

        #[Column(type: MySqlType::Datetime, nullable: true)]
        public ?DateTimeImmutable $deletedAt,

        #[BelongsTo(
            target: ValidCategoryTableModel::class,
            foreignKey: 'categoryId',
            writePolicy: RelationWritePolicy::ReferenceOnly,
        )]
        public ?RelationState $category = null,

        #[HasMany(
            target: ValidReviewTableModel::class,
            foreignKey: 'productId',
            writePolicy: RelationWritePolicy::CascadeOwned,
        )]
        public ?RelationState $reviews = null,
    ) {}
}

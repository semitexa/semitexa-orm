<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Metadata;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\BelongsTo;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;

#[FromTable(name: 'invalid_relation_policy_models')]
final readonly class InvalidRelationPolicyTableModel
{
    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,

        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $categoryId,

        #[BelongsTo(target: ValidCategoryTableModel::class, foreignKey: 'categoryId')]
        public ?ValidCategoryTableModel $category = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Collection;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Metadata\HasColumnReferences;
use Semitexa\Orm\Metadata\HasRelationReferences;

/**
 * One Way Phase 2 fixture: a minimal un-scoped model (no tenant policy,
 * no soft delete) so collection-compiler SQL assertions read clean —
 * mirrors the live `ui_playground_pings` shape plus a second string
 * column (`body`) to exercise the multi-member search OR-group.
 */
#[FromTable(name: 'collection_pings')]
final readonly class CollectionPingResourceModel
{
    use HasColumnReferences;
    use HasRelationReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'manual')]
        #[Column(type: MySqlType::Varchar, length: 32)]
        public string $id,

        #[Column(type: MySqlType::Varchar, length: 120)]
        public string $label,

        #[Column(type: MySqlType::Varchar, length: 120)]
        public string $body,

        #[Column(type: MySqlType::Datetime)]
        public \DateTimeImmutable $created_at,
    ) {
    }
}

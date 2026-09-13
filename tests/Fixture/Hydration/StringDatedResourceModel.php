<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Hydration;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;

/**
 * A resource model that declares its datetime columns as `string`.
 *
 * The schema validator permits this, and nothing in the framework's own code
 * does it — which is why hydrating such a model fatalled for as long as it did.
 * The fixture exists so the combination is exercised by something, rather than
 * being a shape only the validator's own unit tests know about.
 */
#[FromTable(name: 'string_dated_rows')]
final readonly class StringDatedResourceModel
{
    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,

        #[Column(type: MySqlType::Datetime)]
        public string $createdAt,

        #[Column(type: MySqlType::Date, nullable: true)]
        public ?string $bornOn,
    ) {}
}

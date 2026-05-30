<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Metadata;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Attribute\ResourceKey;

/**
 * Fixture for the explicit #[ResourceKey] override: its scope key ('custom_key')
 * differs from its table name ('keyed_things').
 */
#[FromTable(name: 'keyed_things')]
#[ResourceKey('custom_key')]
final readonly class KeyedResourceModel
{
    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,

        #[Column(type: MySqlType::Varchar, length: 255)]
        public string $name,
    ) {}
}

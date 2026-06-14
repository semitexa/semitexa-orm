<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Metadata;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Domain\Model\ResourceMetadata;
use Semitexa\Orm\Tests\Fixture\Metadata\KeyedResourceModel;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidProductResourceModel;

final class ResourceMetadataResourceKeyTest extends TestCase
{
    protected function setUp(): void
    {
        ResourceMetadata::reset();
    }

    protected function tearDown(): void
    {
        ResourceMetadata::reset();
    }

    #[Test]
    public function resource_key_defaults_to_table_name_when_attribute_absent(): void
    {
        $metadata = ResourceMetadata::for(ValidProductResourceModel::class);

        $this->assertSame('products', $metadata->getTableName());
        $this->assertSame('products', $metadata->getResourceKey());
    }

    #[Test]
    public function resource_key_override_takes_precedence_over_table_name(): void
    {
        $metadata = ResourceMetadata::for(KeyedResourceModel::class);

        $this->assertSame('keyed_things', $metadata->getTableName());
        $this->assertSame('custom_key', $metadata->getResourceKey());
    }
}

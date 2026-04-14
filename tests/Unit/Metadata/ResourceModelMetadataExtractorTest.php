<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Metadata;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Metadata\RelationKind;
use Semitexa\Orm\Metadata\ResourceModelMetadataExtractor;
use Semitexa\Orm\Persistence\RelationWritePolicy;
use Semitexa\Orm\Tests\Fixture\Metadata\AnalyticsEventResourceModel;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidProductResourceModel;

final class ResourceModelMetadataExtractorTest extends TestCase
{
    #[Test]
    public function extracts_resource_model_metadata(): void
    {
        $metadata = (new ResourceModelMetadataExtractor())->extract(ValidProductResourceModel::class);

        $this->assertSame(ValidProductResourceModel::class, $metadata->className);
        $this->assertSame('products', $metadata->tableName);
        $this->assertSame('id', $metadata->primaryKeyProperty);
        $this->assertTrue($metadata->hasColumn('tenantId'));
        $this->assertTrue($metadata->hasRelation('category'));
        $this->assertTrue($metadata->hasRelation('reviews'));

        $tenantColumn = $metadata->column('tenantId');
        $this->assertSame('tenantId', $tenantColumn->propertyName);
        $this->assertSame('tenantId', $tenantColumn->columnName);

        $categoryRelation = $metadata->relation('category');
        $this->assertSame(RelationKind::BelongsTo, $categoryRelation->kind);
        $this->assertSame('categoryId', $categoryRelation->foreignKey);
        $this->assertSame(RelationWritePolicy::ReferenceOnly, $categoryRelation->writePolicy);

        $reviewsRelation = $metadata->relation('reviews');
        $this->assertSame(RelationKind::HasMany, $reviewsRelation->kind);
        $this->assertSame(RelationWritePolicy::CascadeOwned, $reviewsRelation->writePolicy);

        $this->assertNotNull($metadata->tenantPolicy);
        $this->assertSame('column', $metadata->tenantPolicy?->strategy);
        $this->assertSame('tenantId', $metadata->tenantPolicy?->column);

        $this->assertNotNull($metadata->softDelete);
        $this->assertSame('deletedAt', $metadata->softDelete?->propertyName);
    }

    #[Test]
    public function defaults_connection_name_to_default(): void
    {
        $metadata = (new ResourceModelMetadataExtractor())->extract(ValidProductResourceModel::class);

        $this->assertSame('default', $metadata->connectionName);
    }

    #[Test]
    public function extracts_explicit_connection_name(): void
    {
        $metadata = (new ResourceModelMetadataExtractor())->extract(AnalyticsEventResourceModel::class);

        $this->assertSame('analytics', $metadata->connectionName);
        $this->assertSame('analytics_events', $metadata->tableName);
    }
}

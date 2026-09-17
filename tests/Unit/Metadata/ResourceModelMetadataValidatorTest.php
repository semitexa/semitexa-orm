<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Metadata;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Exception\InvalidRelationDeclarationException;
use Semitexa\Orm\Exception\InvalidSoftDeleteDeclarationException;
use Semitexa\Orm\Exception\InvalidTenantPolicyException;
use Semitexa\Orm\Metadata\ResourceModelMetadataExtractor;
use Semitexa\Orm\Metadata\ResourceModelMetadataValidator;
use Semitexa\Orm\Tests\Fixture\Metadata\DirectTenantColumnResourceModel;

use Semitexa\Orm\Tests\Fixture\Metadata\InvalidRelationPolicyResourceModel;

use Semitexa\Orm\Tests\Fixture\Metadata\InvalidRelationTargetResourceModel;

use Semitexa\Orm\Tests\Fixture\Metadata\InvalidSyncPivotBelongsToResourceModel;

use Semitexa\Orm\Tests\Fixture\Metadata\InvalidSoftDeleteResourceModel;

use Semitexa\Orm\Tests\Fixture\Metadata\ImplicitTenantColumnResourceModel;
use Semitexa\Orm\Tests\Fixture\Metadata\InvalidTenantResourceModel;

use Semitexa\Orm\Tests\Fixture\Metadata\ValidProductResourceModel;

final class ResourceModelMetadataValidatorTest extends TestCase
{
    private ResourceModelMetadataExtractor $extractor;
    private ResourceModelMetadataValidator $validator;

    protected function setUp(): void
    {
        $this->extractor = new ResourceModelMetadataExtractor();
        $this->validator = new ResourceModelMetadataValidator();
    }

    #[Test]
    public function validates_a_correct_resource_model(): void
    {
        $metadata = $this->extractor->extract(ValidProductResourceModel::class);

        $this->validator->validate($metadata);

        $this->assertTrue(true);
    }

    #[Test]
    public function accepts_tenant_policy_declared_with_sql_column_name(): void
    {
        $metadata = $this->extractor->extract(DirectTenantColumnResourceModel::class);

        $this->validator->validate($metadata);

        $this->assertTrue(true);
    }

    #[Test]
    public function rejects_a_tenant_policy_that_declares_no_column_at_all(): void
    {
        // `#[TenantScoped]` with every default is writable — `column` defaults
        // to null — and this refusal is what keeps it from reaching
        // ResourceModelMetadata::tenantColumn(), which has no null to match
        // against and would throw a LogicException naming an empty column at
        // query time instead. The guard is here, at boot, with a reason; the
        // branch just had no test, so it looked absent.
        $metadata = $this->extractor->extract(ImplicitTenantColumnResourceModel::class);

        $this->expectException(InvalidTenantPolicyException::class);
        $this->expectExceptionMessageMatches('/must declare a tenant column/');

        $this->validator->validate($metadata);
    }

    #[Test]
    public function rejects_missing_tenant_column(): void
    {
        $metadata = $this->extractor->extract(InvalidTenantResourceModel::class);

        $this->expectException(InvalidTenantPolicyException::class);

        $this->validator->validate($metadata);
    }

    #[Test]
    public function rejects_non_nullable_soft_delete_column(): void
    {
        $metadata = $this->extractor->extract(InvalidSoftDeleteResourceModel::class);

        $this->expectException(InvalidSoftDeleteDeclarationException::class);

        $this->validator->validate($metadata);
    }

    #[Test]
    public function rejects_missing_relation_write_policy(): void
    {
        $metadata = $this->extractor->extract(InvalidRelationPolicyResourceModel::class);

        $this->expectException(InvalidRelationDeclarationException::class);

        $this->validator->validate($metadata);
    }

    #[Test]
    public function rejects_missing_relation_target_class(): void
    {
        $metadata = $this->extractor->extract(InvalidRelationTargetResourceModel::class);

        $this->expectException(InvalidRelationDeclarationException::class);

        $this->validator->validate($metadata);
    }

    #[Test]
    public function rejects_sync_pivot_only_on_non_many_to_many_relations(): void
    {
        $metadata = $this->extractor->extract(InvalidSyncPivotBelongsToResourceModel::class);

        $this->expectException(InvalidRelationDeclarationException::class);

        $this->validator->validate($metadata);
    }
}

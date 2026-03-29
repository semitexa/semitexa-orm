<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Metadata;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Exception\InvalidRelationDeclarationException;
use Semitexa\Orm\Exception\InvalidSoftDeleteDeclarationException;
use Semitexa\Orm\Exception\InvalidTenantPolicyException;
use Semitexa\Orm\Metadata\TableModelMetadataExtractor;
use Semitexa\Orm\Metadata\TableModelMetadataValidator;
use Semitexa\Orm\Tests\Fixture\Metadata\InvalidRelationPolicyTableModel;
use Semitexa\Orm\Tests\Fixture\Metadata\InvalidRelationTargetTableModel;
use Semitexa\Orm\Tests\Fixture\Metadata\InvalidSyncPivotBelongsToTableModel;
use Semitexa\Orm\Tests\Fixture\Metadata\InvalidSoftDeleteTableModel;
use Semitexa\Orm\Tests\Fixture\Metadata\InvalidTenantTableModel;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidProductTableModel;

require_once __DIR__ . '/../../Fixture/Metadata/ValidCategoryTableModel.php';
require_once __DIR__ . '/../../Fixture/Metadata/ValidReviewTableModel.php';
require_once __DIR__ . '/../../Fixture/Metadata/ValidProductTableModel.php';
require_once __DIR__ . '/../../Fixture/Metadata/InvalidTenantTableModel.php';
require_once __DIR__ . '/../../Fixture/Metadata/InvalidSoftDeleteTableModel.php';
require_once __DIR__ . '/../../Fixture/Metadata/InvalidRelationPolicyTableModel.php';
require_once __DIR__ . '/../../Fixture/Metadata/InvalidRelationTargetTableModel.php';
require_once __DIR__ . '/../../Fixture/Metadata/InvalidSyncPivotBelongsToTableModel.php';

final class TableModelMetadataValidatorTest extends TestCase
{
    private TableModelMetadataExtractor $extractor;
    private TableModelMetadataValidator $validator;

    protected function setUp(): void
    {
        $this->extractor = new TableModelMetadataExtractor();
        $this->validator = new TableModelMetadataValidator();
    }

    #[Test]
    public function validates_a_correct_table_model(): void
    {
        $metadata = $this->extractor->extract(ValidProductTableModel::class);

        $this->validator->validate($metadata);

        $this->assertTrue(true);
    }

    #[Test]
    public function rejects_missing_tenant_column(): void
    {
        $metadata = $this->extractor->extract(InvalidTenantTableModel::class);

        $this->expectException(InvalidTenantPolicyException::class);

        $this->validator->validate($metadata);
    }

    #[Test]
    public function rejects_non_nullable_soft_delete_column(): void
    {
        $metadata = $this->extractor->extract(InvalidSoftDeleteTableModel::class);

        $this->expectException(InvalidSoftDeleteDeclarationException::class);

        $this->validator->validate($metadata);
    }

    #[Test]
    public function rejects_missing_relation_write_policy(): void
    {
        $metadata = $this->extractor->extract(InvalidRelationPolicyTableModel::class);

        $this->expectException(InvalidRelationDeclarationException::class);

        $this->validator->validate($metadata);
    }

    #[Test]
    public function rejects_missing_relation_target_class(): void
    {
        $metadata = $this->extractor->extract(InvalidRelationTargetTableModel::class);

        $this->expectException(InvalidRelationDeclarationException::class);

        $this->validator->validate($metadata);
    }

    #[Test]
    public function rejects_sync_pivot_only_on_non_many_to_many_relations(): void
    {
        $metadata = $this->extractor->extract(InvalidSyncPivotBelongsToTableModel::class);

        $this->expectException(InvalidRelationDeclarationException::class);

        $this->validator->validate($metadata);
    }
}

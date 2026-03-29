<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Metadata;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Metadata\ColumnRef;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidProductTableModel;

require_once __DIR__ . '/../../Fixture/Metadata/ValidCategoryTableModel.php';
require_once __DIR__ . '/../../Fixture/Metadata/ValidReviewTableModel.php';
require_once __DIR__ . '/../../Fixture/Metadata/ValidProductTableModel.php';

final class ColumnRefTest extends TestCase
{
    #[Test]
    public function can_build_a_column_reference_from_a_table_model(): void
    {
        $ref = ValidProductTableModel::column('tenantId');

        $this->assertInstanceOf(ColumnRef::class, $ref);
        $this->assertSame(ValidProductTableModel::class, $ref->tableModelClass);
        $this->assertSame('tenantId', $ref->propertyName);
        $this->assertSame('tenantId', $ref->columnName);
    }

    #[Test]
    public function rejects_unknown_column_properties(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ValidProductTableModel::column('missingProperty');
    }
}

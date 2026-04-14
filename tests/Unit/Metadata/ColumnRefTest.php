<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Metadata;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Metadata\ColumnRef;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidProductResourceModel;

final class ColumnRefTest extends TestCase
{
    #[Test]
    public function can_build_a_column_reference_from_a_resource_model(): void
    {
        $ref = ValidProductResourceModel::column('tenantId');

        $this->assertInstanceOf(ColumnRef::class, $ref);
        $this->assertSame(ValidProductResourceModel::class, $ref->resourceModelClass);
        $this->assertSame('tenantId', $ref->propertyName);
        $this->assertSame('tenantId', $ref->columnName);
    }

    #[Test]
    public function rejects_unknown_column_properties(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ValidProductResourceModel::column('missingProperty');
    }
}

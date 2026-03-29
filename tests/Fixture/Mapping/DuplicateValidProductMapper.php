<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Mapping;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Contract\TableModelMapper;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidProductTableModel;

#[AsMapper(tableModel: ValidProductTableModel::class, domainModel: ValidProductDomainModel::class)]
final class DuplicateValidProductMapper implements TableModelMapper
{
    public function toDomain(object $tableModel): object
    {
        return new ValidProductDomainModel(
            id: 'duplicate',
            tenantId: 'duplicate',
            name: 'duplicate',
            categoryId: 'duplicate',
        );
    }

    public function toTableModel(object $domainModel): object
    {
        return new ValidProductTableModel(
            id: 'duplicate',
            tenantId: 'duplicate',
            name: 'duplicate',
            categoryId: 'duplicate',
        );
    }
}

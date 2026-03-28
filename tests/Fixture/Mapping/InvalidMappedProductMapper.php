<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Mapping;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Contract\TableModelMapper;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidProductTableModel;

#[AsMapper(tableModel: ValidProductTableModel::class, domainModel: InvalidMappedDomainModel::class)]
final class InvalidMappedProductMapper implements TableModelMapper
{
    public function toDomain(object $tableModel): object
    {
        return new InvalidMappedDomainModel(id: 'invalid');
    }

    public function toTableModel(object $domainModel): object
    {
        return new ValidProductTableModel(
            id: 'invalid',
            tenantId: 'invalid',
            name: 'invalid',
            categoryId: 'invalid',
        );
    }
}

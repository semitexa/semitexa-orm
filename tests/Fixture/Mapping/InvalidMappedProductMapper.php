<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Mapping;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Contract\ResourceModelMapperInterface;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidProductResourceModel;

#[AsMapper(resourceModel: ValidProductResourceModel::class, domainModel: InvalidMappedDomainModel::class)]
final class InvalidMappedProductMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        return new InvalidMappedDomainModel(id: 'invalid');
    }

    public function toSourceModel(object $domainModel): object
    {
        return new ValidProductResourceModel(
            id: 'invalid',
            tenantId: 'invalid',
            name: 'invalid',
            categoryId: 'invalid',
        );
    }
}

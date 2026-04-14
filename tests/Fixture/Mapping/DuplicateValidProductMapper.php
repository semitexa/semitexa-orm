<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Mapping;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Contract\ResourceModelMapperInterface;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidProductResourceModel;

#[AsMapper(resourceModel: ValidProductResourceModel::class, domainModel: ValidProductDomainModel::class)]
final class DuplicateValidProductMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        return new ValidProductDomainModel(
            id: 'duplicate',
            tenantId: 'duplicate',
            name: 'duplicate',
            categoryId: 'duplicate',
        );
    }

    public function toSourceModel(object $domainModel): object
    {
        return new ValidProductResourceModel(
            id: 'duplicate',
            tenantId: 'duplicate',
            name: 'duplicate',
            categoryId: 'duplicate',
        );
    }
}

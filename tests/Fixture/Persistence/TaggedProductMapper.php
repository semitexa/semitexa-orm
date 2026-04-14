<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Persistence;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Contract\ResourceModelMapperInterface;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidTaggedProductResourceModel;

#[AsMapper(resourceModel: ValidTaggedProductResourceModel::class, domainModel: TaggedProductDomainModel::class)]
final class TaggedProductMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof ValidTaggedProductResourceModel || throw new \InvalidArgumentException('Unexpected resource model.');

        return new TaggedProductDomainModel(
            id: $resourceModel->id,
            name: $resourceModel->name,
            tagIds: array_map(
                static fn (mixed $tag): string => is_string($tag) ? $tag : $tag->id,
                $resourceModel->tags,
            ),
        );
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof TaggedProductDomainModel || throw new \InvalidArgumentException('Unexpected domain model.');

        return new ValidTaggedProductResourceModel(
            id: $domainModel->id,
            name: $domainModel->name,
            tags: $domainModel->tagIds,
        );
    }
}

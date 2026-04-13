<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Persistence;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Contract\TableModelMapper;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidTaggedProductTableModel;

#[AsMapper(resourceModel: ValidTaggedProductTableModel::class, domainModel: TaggedProductDomainModel::class)]
final class TaggedProductMapper implements TableModelMapper
{
    public function toDomain(object $tableModel): object
    {
        $tableModel instanceof ValidTaggedProductTableModel || throw new \InvalidArgumentException('Unexpected table model.');

        return new TaggedProductDomainModel(
            id: $tableModel->id,
            name: $tableModel->name,
            tagIds: array_map(
                static fn (mixed $tag): string => is_string($tag) ? $tag : $tag->id,
                $tableModel->tags,
            ),
        );
    }

    public function toTableModel(object $domainModel): object
    {
        $domainModel instanceof TaggedProductDomainModel || throw new \InvalidArgumentException('Unexpected domain model.');

        return new ValidTaggedProductTableModel(
            id: $domainModel->id,
            name: $domainModel->name,
            tags: $domainModel->tagIds,
        );
    }
}

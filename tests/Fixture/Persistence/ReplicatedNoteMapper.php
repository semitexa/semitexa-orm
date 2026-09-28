<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Persistence;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Orm\Tests\Fixture\Metadata\ReplicatedNoteResourceModel;

#[AsMapper(resourceModel: ReplicatedNoteResourceModel::class, domainModel: ReplicatedNote::class)]
final class ReplicatedNoteMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof ReplicatedNoteResourceModel || throw new \InvalidArgumentException('Unexpected resource model.');

        return new ReplicatedNote($resourceModel->id, $resourceModel->title, $resourceModel->body);
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof ReplicatedNote || throw new \InvalidArgumentException('Unexpected domain model.');

        return new ReplicatedNoteResourceModel($domainModel->id, $domainModel->title, $domainModel->body);
    }
}

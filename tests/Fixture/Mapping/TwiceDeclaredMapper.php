<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Mapping;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidProductResourceModel;

/**
 * One class trying to be two mappers.
 *
 * `AsMapper` is `#[Attribute(Attribute::TARGET_CLASS)]` and therefore not
 * repeatable, so PHP refuses to instantiate the attribute at all. The file still
 * PARSES — this fixture exists because the refusal happens at reflection time,
 * which is where the registry has to turn it into an error that names the class.
 */
#[AsMapper(resourceModel: ValidProductResourceModel::class, domainModel: ValidProductDomainModel::class)]
#[AsMapper(resourceModel: ValidProductResourceModel::class, domainModel: InvalidMappedDomainModel::class)]
final class TwiceDeclaredMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        return new InvalidMappedDomainModel(id: 'unused');
    }

    public function toSourceModel(object $domainModel): object
    {
        return new ValidProductResourceModel();
    }
}

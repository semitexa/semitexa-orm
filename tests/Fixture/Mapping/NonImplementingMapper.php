<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Mapping;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidProductResourceModel;

#[AsMapper(resourceModel: ValidProductResourceModel::class, domainModel: ValidProductDomainModel::class)]
final class NonImplementingMapper
{
}

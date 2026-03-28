<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Mapping;

final readonly class InvalidMappedDomainModel
{
    public function __construct(
        public string $id,
    ) {}
}

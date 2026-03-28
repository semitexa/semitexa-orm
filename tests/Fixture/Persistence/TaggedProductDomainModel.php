<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Persistence;

final readonly class TaggedProductDomainModel
{
    /**
     * @param list<string> $tagIds
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $tagIds = [],
    ) {}
}

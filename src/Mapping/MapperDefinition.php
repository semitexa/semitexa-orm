<?php

declare(strict_types=1);

namespace Semitexa\Orm\Mapping;

final readonly class MapperDefinition
{
    public function __construct(
        public string $mapperClass,
        public string $resourceModelClass,
        public string $domainModelClass,
    ) {}

    public function key(): string
    {
        return $this->resourceModelClass . "\0" . $this->domainModelClass;
    }
}

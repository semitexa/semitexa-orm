<?php

declare(strict_types=1);

namespace Semitexa\Orm\Mapping;

final readonly class MapperDefinition
{
    public function __construct(
        public string $mapperClass,
        public string $tableModelClass,
        public string $domainModelClass,
    ) {}

    public function key(): string
    {
        return $this->tableModelClass . "\0" . $this->domainModelClass;
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Orm\Discovery;

use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Orm\Attribute\AsRepository;

final class RepositoryDiscovery
{
    public function __construct(
        private readonly ClassDiscovery $classDiscovery,
    ) {}

    /**
     * @return list<class-string>
     */
    public function discoverRepositoryClasses(): array
    {
        return $this->classDiscovery->findClassesWithAttribute(AsRepository::class);
    }
}

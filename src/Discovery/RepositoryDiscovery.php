<?php

declare(strict_types=1);

namespace Semitexa\Orm\Discovery;

use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Orm\Attribute\AsRepository;

final class RepositoryDiscovery
{
    /**
     * @return list<class-string>
     */
    public static function discoverRepositoryClasses(): array
    {
        return ClassDiscovery::findClassesWithAttribute(AsRepository::class);
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service\Persistence;

use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Replicated;
use Semitexa\Orm\Metadata\ResourceModelMetadataRegistry;

/**
 * Registers every #[Replicated] resource's table with the ReplicatedWriteGuard.
 * Run once per process, from the server and the console lifecycle.
 */
final class ReplicatedTableRegistration
{
    public static function fromDiscovery(ClassDiscovery $discovery): void
    {
        $metadata = new ResourceModelMetadataRegistry();

        foreach ($discovery->findClassesWithAttribute(Replicated::class) as $class) {
            if (!class_exists($class) || (new \ReflectionClass($class))->getAttributes(FromTable::class) === []) {
                continue;
            }
            ReplicatedWriteGuard::register($metadata->for($class)->tableName);
        }
    }
}

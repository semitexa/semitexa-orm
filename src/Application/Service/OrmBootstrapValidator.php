<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service;

use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Domain\Model\OrmBootstrapReport;
use Semitexa\Orm\Application\Service\Mapping\MapperRegistry;
use Semitexa\Orm\Metadata\ResourceModelMetadata;
use Semitexa\Orm\Metadata\ResourceModelMetadataRegistry;

final class OrmBootstrapValidator
{
    public function __construct(
        private readonly ?ClassDiscovery                $classDiscovery = null,
        private readonly ?ResourceModelMetadataRegistry $metadataRegistry = null,
        private readonly ?MapperRegistry                $mapperRegistry = null,
    ) {}

    /**
     * @param list<class-string>|null $resourceModelClasses
     * @param list<class-string>|null $mapperClasses
     * @param list<class-string>|null $domainModelClasses
     */
    public function validate(
        ?array $resourceModelClasses = null,
        ?array $mapperClasses = null,
        ?array $domainModelClasses = null,
    ): OrmBootstrapReport {
        // Remembered before the defaults are filled in, because "the caller named
        // a subset" and "we discovered everything" are the same array afterwards
        // and must not lead to the same decision below.
        $mapperClassesWereGiven = $mapperClasses !== null;

        $resourceModelClasses ??= $this->classDiscovery()->findClassesWithAttribute(FromTable::class);
        $mapperClasses ??= $this->classDiscovery()->findClassesWithAttribute(AsMapper::class);
        /** @var list<class-string> $resourceModelClasses */
        /** @var list<class-string> $mapperClasses */

        $metadataRegistry = $this->metadataRegistry ?? ResourceModelMetadataRegistry::default();
        /** @var array<class-string, ResourceModelMetadata> $metadataByClass */
        $metadataByClass = [];
        foreach ($resourceModelClasses as $resourceModelClass) {
            $metadataByClass[$resourceModelClass] = $metadataRegistry->for($resourceModelClass);
        }

        // Detect cross-connection relations
        $crossConnectionWarnings = [];
        foreach ($metadataByClass as $className => $metadata) {
            foreach ($metadata->relations() as $relation) {
                $targetClass = $relation->targetClass;
                if (!isset($metadataByClass[$targetClass])) {
                    continue;
                }
                $targetMetadata = $metadataByClass[$targetClass];
                if ($metadata->connectionName !== $targetMetadata->connectionName) {
                    $crossConnectionWarnings[] = sprintf(
                        '%s (%s) -> %s (%s) via property "%s"',
                        $className,
                        $metadata->connectionName,
                        $targetClass,
                        $targetMetadata->connectionName,
                        $relation->propertyName,
                    );
                }
            }
        }

        $mapperRegistry = $this->registryToInspect($mapperClasses, $mapperClassesWereGiven);

        $domainModelClasses ??= array_values(array_unique(array_map(
            static fn ($definition) => $definition->domainModelClass,
            $mapperRegistry->all(),
        )));
        /** @var list<class-string> $domainModelClasses */

        return new OrmBootstrapReport(
            resourceModelClasses: $resourceModelClasses,
            mapperClasses: $mapperClasses,
            domainModelClasses: $domainModelClasses,
            crossConnectionWarnings: $crossConnectionWarnings,
        );
    }

    /**
     * The registry this report describes — never one this validator has to
     * damage to produce it.
     *
     * A DIAGNOSTIC MUST NOT REBUILD WHAT IT INSPECTS. This used to call
     * build() on the injected registry, and OrmManager::getBootstrapValidator()
     * injects the LIVE one. With no arguments that rebuilt the same content and
     * threw away the memoized mapper instances; with a subset —
     * `validate(mapperClasses: [OneMapper::class])`, which is the public API and
     * the shape a doctor check naturally writes — it left the application's
     * registry holding exactly that one mapper, and every other mapToDomain()
     * in the worker threw MissingMapperException until something rebuilt it.
     * Nothing does: OrmManager builds the registry only when its field is null.
     *
     * The same hazard the memoization comment on getMapperRegistry() was written
     * for: build() walks the classmap through ClassDiscovery, whose autoloads
     * suspend the coroutine under SWOOLE_HOOK_ALL, so a rebuild is visible to
     * every request in flight on that worker.
     *
     * So: a caller-named subset is always inspected in a registry of this
     * method's own making. Otherwise the live one is read as it stands — it was
     * built from the same classmap this validate() just walked — and only when
     * it holds nothing (never built, or genuinely empty) is a local one built,
     * which yields the same answer either way.
     *
     * @param list<class-string> $mapperClasses
     */
    private function registryToInspect(array $mapperClasses, bool $mapperClassesWereGiven): MapperRegistry
    {
        if (!$mapperClassesWereGiven && $this->mapperRegistry !== null && $this->mapperRegistry->all() !== []) {
            return $this->mapperRegistry;
        }

        $registry = new MapperRegistry($this->classDiscovery);
        $registry->build(mapperClasses: $mapperClasses);

        return $registry;
    }

    private function classDiscovery(): ClassDiscovery
    {
        return $this->classDiscovery ?? new ClassDiscovery();
    }
}

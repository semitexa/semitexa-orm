<?php

declare(strict_types=1);

namespace Semitexa\Orm\Bootstrap;

use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Mapping\MapperRegistry;
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

        $mapperRegistry = $this->mapperRegistry ?? new MapperRegistry($this->classDiscovery);
        $mapperRegistry->build(
            mapperClasses: $mapperClasses,
        );

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

    private function classDiscovery(): ClassDiscovery
    {
        return $this->classDiscovery ?? new ClassDiscovery();
    }
}

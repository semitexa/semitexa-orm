<?php

declare(strict_types=1);

namespace Semitexa\Orm\Bootstrap;

use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Mapping\MapperRegistry;
use Semitexa\Orm\Metadata\TableModelMetadataRegistry;

final class OrmBootstrapValidator
{
    public function __construct(
        private readonly ?ClassDiscovery $classDiscovery = null,
        private readonly ?TableModelMetadataRegistry $metadataRegistry = null,
        private readonly ?MapperRegistry $mapperRegistry = null,
    ) {}

    /**
     * @param list<class-string>|null $tableModelClasses
     * @param list<class-string>|null $mapperClasses
     * @param list<class-string>|null $domainModelClasses
     */
    public function validate(
        ?array $tableModelClasses = null,
        ?array $mapperClasses = null,
        ?array $domainModelClasses = null,
    ): OrmBootstrapReport {
        $tableModelClasses ??= $this->classDiscovery()->findClassesWithAttribute(FromTable::class);
        $mapperClasses ??= $this->classDiscovery()->findClassesWithAttribute(AsMapper::class);

        $metadataRegistry = $this->metadataRegistry ?? TableModelMetadataRegistry::default();
        foreach ($tableModelClasses as $tableModelClass) {
            $metadataRegistry->for($tableModelClass);
        }

        $mapperRegistry = $this->mapperRegistry ?? new MapperRegistry($this->classDiscovery);
        $mapperRegistry->build(
            mapperClasses: array_values($mapperClasses),
        );

        $domainModelClasses ??= array_values(array_unique(array_map(
            static fn ($definition) => $definition->domainModelClass,
            $mapperRegistry->all(),
        )));

        return new OrmBootstrapReport(
            tableModelClasses: array_values($tableModelClasses),
            mapperClasses: array_values($mapperClasses),
            domainModelClasses: array_values($domainModelClasses),
        );
    }

    private function classDiscovery(): ClassDiscovery
    {
        return $this->classDiscovery ?? new ClassDiscovery();
    }
}

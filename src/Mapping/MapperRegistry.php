<?php

declare(strict_types=1);

namespace Semitexa\Orm\Mapping;

use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Contract\ResourceModelMapperInterface;
use Semitexa\Orm\Exception\DuplicateMapperException;
use Semitexa\Orm\Exception\InvalidMapperDeclarationException;
use Semitexa\Orm\Exception\MissingMapperException;

final class MapperRegistry
{
    /** @var array<string, MapperDefinition> */
    private array $definitionsByPair = [];

    /** @var array<class-string, ResourceModelMapperInterface> */
    private array $instancesByMapperClass = [];

    public function __construct(
        private readonly ?ClassDiscovery $classDiscovery = null,
    ) {}

    /**
     * @param list<class-string>|null $mapperClasses
     * @param list<class-string>|null $domainModelClasses
     */
    public function build(?array $mapperClasses = null, ?array $domainModelClasses = null): void
    {
        $mapperClasses ??= $this->classDiscovery()->findClassesWithAttribute(AsMapper::class);
        /** @var list<class-string> $mapperClasses */

        $definitionsByPair = [];

        foreach ($mapperClasses as $mapperClass) {
            $definition = $this->extractMapperDefinition($mapperClass);
            $key = $definition->key();

            if (isset($definitionsByPair[$key])) {
                throw new DuplicateMapperException(sprintf(
                    'Duplicate mapper declarations for %s <-> %s: %s and %s.',
                    $definition->resourceModelClass,
                    $definition->domainModelClass,
                    $definitionsByPair[$key]->mapperClass,
                    $definition->mapperClass,
                ));
            }

            $definitionsByPair[$key] = $definition;
        }

        $this->definitionsByPair = $definitionsByPair;
        /** @var array<class-string, ResourceModelMapperInterface> $instancesByMapperClass */
        $instancesByMapperClass = [];
        $this->instancesByMapperClass = $instancesByMapperClass;
    }

    public function definitionFor(string $resourceModelClass, string $domainModelClass): MapperDefinition
    {
        $key = $resourceModelClass . "\0" . $domainModelClass;
        if (!isset($this->definitionsByPair[$key])) {
            throw new MissingMapperException(sprintf(
                'No mapper registered for %s and %s.',
                $resourceModelClass,
                $domainModelClass,
            ));
        }

        return $this->definitionsByPair[$key];
    }

    public function mapperFor(string $resourceModelClass, string $domainModelClass): ResourceModelMapperInterface
    {
        $definition = $this->definitionFor($resourceModelClass, $domainModelClass);

        if (!isset($this->instancesByMapperClass[$definition->mapperClass])) {
            /** @var ResourceModelMapperInterface $mapper */
            $mapper = new ($definition->mapperClass)();
            $this->instancesByMapperClass[$definition->mapperClass] = $mapper;
        }

        return $this->instancesByMapperClass[$definition->mapperClass];
    }

    public function mapToDomain(object $resourceModel, string $domainModelClass): object
    {
        return $this->mapperFor($resourceModel::class, $domainModelClass)->toDomain($resourceModel);
    }

    public function mapToSourceModel(object $domainModel, string $resourceModelClass): object
    {
        $definition = $this->definitionFor($resourceModelClass, $domainModel::class);

        return $this->mapperFor($definition->resourceModelClass, $definition->domainModelClass)->toSourceModel($domainModel);
    }

    /**
     * @return array<string, MapperDefinition>
     */
    public function all(): array
    {
        return $this->definitionsByPair;
    }

    /**
     * @param class-string $mapperClass
     */
    private function extractMapperDefinition(string $mapperClass): MapperDefinition
    {
        $ref = new \ReflectionClass($mapperClass);
        $attrs = $ref->getAttributes(AsMapper::class);
        if ($attrs === []) {
            throw new InvalidMapperDeclarationException(sprintf(
                'Mapper class %s is missing #[AsMapper].',
                $mapperClass,
            ));
        }

        if (!$ref->implementsInterface(ResourceModelMapperInterface::class)) {
            throw new InvalidMapperDeclarationException(sprintf(
                'Mapper class %s must implement %s.',
                $mapperClass,
                ResourceModelMapperInterface::class,
            ));
        }

        /** @var AsMapper $asMapper */
        $asMapper = $attrs[0]->newInstance();
        /** @var class-string $resourceModelClass */
        $resourceModelClass = $asMapper->resourceModel;
        /** @var class-string $domainModelClass */
        $domainModelClass = $asMapper->domainModel;

        if (!class_exists($resourceModelClass)) {
            throw new InvalidMapperDeclarationException(sprintf(
                'Mapper %s declares missing resource model %s.',
                $mapperClass,
                $resourceModelClass,
            ));
        }

        if (!class_exists($domainModelClass)) {
            throw new InvalidMapperDeclarationException(sprintf(
                'Mapper %s declares missing domain model %s.',
                $mapperClass,
                $domainModelClass,
            ));
        }

        return new MapperDefinition(
            mapperClass: $mapperClass,
            resourceModelClass: $resourceModelClass,
            domainModelClass: $domainModelClass,
        );
    }

    private function classDiscovery(): ClassDiscovery
    {
        return $this->classDiscovery ?? new ClassDiscovery();
    }
}

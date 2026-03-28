<?php

declare(strict_types=1);

namespace Semitexa\Orm\Mapping;

use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Contract\TableModelMapper;
use Semitexa\Orm\Exception\DuplicateMapperException;
use Semitexa\Orm\Exception\InvalidMapperDeclarationException;
use Semitexa\Orm\Exception\MissingMapperException;

final class MapperRegistry
{
    /** @var array<string, MapperDefinition> */
    private array $definitionsByPair = [];

    /** @var array<class-string, MapperDefinition> */
    private array $definitionsByMapperClass = [];

    /** @var array<class-string, TableModelMapper> */
    private array $instancesByMapperClass = [];

    /**
     * @param list<class-string>|null $mapperClasses
     * @param list<class-string>|null $domainModelClasses
     */
    public function build(?array $mapperClasses = null, ?array $domainModelClasses = null): void
    {
        $mapperClasses ??= ClassDiscovery::findClassesWithAttribute(AsMapper::class);

        $definitionsByPair = [];
        $definitionsByMapperClass = [];

        foreach ($mapperClasses as $mapperClass) {
            $definition = $this->extractMapperDefinition($mapperClass);
            $key = $definition->key();

            if (isset($definitionsByPair[$key])) {
                throw new DuplicateMapperException(sprintf(
                    'Duplicate mapper declarations for %s <-> %s: %s and %s.',
                    $definition->tableModelClass,
                    $definition->domainModelClass,
                    $definitionsByPair[$key]->mapperClass,
                    $definition->mapperClass,
                ));
            }

            $definitionsByPair[$key] = $definition;
            $definitionsByMapperClass[$definition->mapperClass] = $definition;
        }

        $this->definitionsByPair = $definitionsByPair;
        $this->definitionsByMapperClass = $definitionsByMapperClass;
        $this->instancesByMapperClass = [];
    }

    public function definitionFor(string $tableModelClass, string $domainModelClass): MapperDefinition
    {
        $key = $tableModelClass . "\0" . $domainModelClass;
        if (!isset($this->definitionsByPair[$key])) {
            throw new MissingMapperException(sprintf(
                'No mapper registered for %s and %s.',
                $tableModelClass,
                $domainModelClass,
            ));
        }

        return $this->definitionsByPair[$key];
    }

    public function mapperFor(string $tableModelClass, string $domainModelClass): TableModelMapper
    {
        $definition = $this->definitionFor($tableModelClass, $domainModelClass);

        if (!isset($this->instancesByMapperClass[$definition->mapperClass])) {
            /** @var TableModelMapper $mapper */
            $mapper = new ($definition->mapperClass)();
            $this->instancesByMapperClass[$definition->mapperClass] = $mapper;
        }

        return $this->instancesByMapperClass[$definition->mapperClass];
    }

    public function mapToDomain(object $tableModel, string $domainModelClass): object
    {
        return $this->mapperFor($tableModel::class, $domainModelClass)->toDomain($tableModel);
    }

    public function mapToTableModel(object $domainModel, string $tableModelClass): object
    {
        $definition = $this->definitionFor($tableModelClass, $domainModel::class);

        return $this->mapperFor($definition->tableModelClass, $definition->domainModelClass)->toTableModel($domainModel);
    }

    /**
     * @return array<string, MapperDefinition>
     */
    public function all(): array
    {
        return $this->definitionsByPair;
    }

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

        if (!$ref->implementsInterface(TableModelMapper::class)) {
            throw new InvalidMapperDeclarationException(sprintf(
                'Mapper class %s must implement %s.',
                $mapperClass,
                TableModelMapper::class,
            ));
        }

        /** @var AsMapper $asMapper */
        $asMapper = $attrs[0]->newInstance();

        if (!class_exists($asMapper->tableModel)) {
            throw new InvalidMapperDeclarationException(sprintf(
                'Mapper %s declares missing table model %s.',
                $mapperClass,
                $asMapper->tableModel,
            ));
        }

        if (!class_exists($asMapper->domainModel)) {
            throw new InvalidMapperDeclarationException(sprintf(
                'Mapper %s declares missing domain model %s.',
                $mapperClass,
                $asMapper->domainModel,
            ));
        }

        return new MapperDefinition(
            mapperClass: $mapperClass,
            tableModelClass: $asMapper->tableModel,
            domainModelClass: $asMapper->domainModel,
        );
    }
}

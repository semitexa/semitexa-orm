<?php

declare(strict_types=1);

namespace Semitexa\Orm\Hydration;

use Semitexa\Orm\Attribute\Column;

class Hydrator
{
    private TypeCaster $typeCaster;

    /** @var array<string, \ReflectionProperty[]> Cached property reflections per class */
    private array $propertyCache = [];

    /** @var array<string, array<string, \ReflectionAttribute<Column>>> Column attributes cache, keyed by property name */
    private array $columnAttrCache = [];

    /**
     * Map: class → [dbColumnName => ReflectionProperty]
     * Needed for hydrate() to look up the row key by DB column name.
     * @var array<string, array<string, \ReflectionProperty>>
     */
    private array $dbColumnToPropertyCache = [];

    public function __construct(?TypeCaster $typeCaster = null)
    {
        $this->typeCaster = $typeCaster ?? new TypeCaster();
    }

    /**
     * Hydrate a single DB row into a Resource object.
     *
     * @template T of object
     * @param array<string, mixed> $row
     * @param class-string<T> $resourceClass
     * @return T
     */
    public function hydrate(array $row, string $resourceClass): object
    {
        $resource = new $resourceClass();
        $dbColumnMap = $this->getDbColumnToPropertyMap($resourceClass);
        $columnAttrs = $this->getColumnAttributes($resourceClass);

        foreach ($dbColumnMap as $dbColumnName => $property) {
            if (!array_key_exists($dbColumnName, $row)) {
                continue;
            }

            $value       = $row[$dbColumnName];
            $propName    = $property->getName();
            $phpType     = $this->resolvePhpType($property);
            $nullable    = $property->getType() instanceof \ReflectionNamedType && $property->getType()->allowsNull();

            if (isset($columnAttrs[$propName])) {
                /** @var Column $colAttr */
                $colAttr = $columnAttrs[$propName]->newInstance();
                $colDef  = new \Semitexa\Orm\Schema\ColumnDefinition(
                    name: $dbColumnName,
                    type: $colAttr->type,
                    phpType: $phpType,
                    nullable: $colAttr->nullable || $nullable,
                    length: $colAttr->length,
                    precision: $colAttr->precision,
                    scale: $colAttr->scale,
                    default: $colAttr->default,
                );
                $value = $this->typeCaster->castFromDb($value, $colDef);
            }

            $castedValue = $this->typeCaster->castToPropertyType($value, $phpType, $nullable);
            $property->setValue($resource, $castedValue);
        }

        return $resource;
    }

    /**
     * Dehydrate a Resource object into a flat associative array for DB storage.
     * Only includes properties that have #[Column] attribute.
     *
     * @param object $resource
     * @return array<string, mixed>
     */
    public function dehydrate(object $resource): array
    {
        $data = [];
        $resourceClass = $resource::class;
        $columnAttrs = $this->getColumnAttributes($resourceClass);

        foreach ($this->getDbColumnToPropertyMap($resourceClass) as $dbColumnName => $property) {
            $propertyName = $property->getName();

            if (!isset($columnAttrs[$propertyName])) {
                continue;
            }

            if (!$property->isInitialized($resource)) {
                continue;
            }

            /** @var Column $column */
            $column = $columnAttrs[$propertyName]->newInstance();
            $value  = $property->getValue($resource);

            $data[$dbColumnName] = $this->typeCaster->castToDb($value, new \Semitexa\Orm\Schema\ColumnDefinition(
                name: $dbColumnName,
                type: $column->type,
                phpType: $this->resolvePhpType($property),
                nullable: $column->nullable || ($property->getType() instanceof \ReflectionNamedType && $property->getType()->allowsNull()),
                length: $column->length,
                precision: $column->precision,
                scale: $column->scale,
                default: $column->default,
            ));
        }

        return $data;
    }

    /**
     * @return \ReflectionProperty[]
     */
    private function getProperties(string $className): array
    {
        if (!isset($this->propertyCache[$className])) {
            $ref = new \ReflectionClass($className);
            $this->propertyCache[$className] = $ref->getProperties(\ReflectionProperty::IS_PUBLIC);
        }

        return $this->propertyCache[$className];
    }

    /**
     * @return array<string, \ReflectionAttribute<Column>> Keyed by PHP property name
     */
    private function getColumnAttributes(string $className): array
    {
        if (!isset($this->columnAttrCache[$className])) {
            $this->buildColumnCaches($className);
        }

        return $this->columnAttrCache[$className];
    }

    /**
     * @return array<string, \ReflectionProperty> Keyed by DB column name
     */
    private function getDbColumnToPropertyMap(string $className): array
    {
        if (!isset($this->dbColumnToPropertyCache[$className])) {
            $this->buildColumnCaches($className);
        }

        return $this->dbColumnToPropertyCache[$className];
    }

    private function buildColumnCaches(string $className): void
    {
        $this->columnAttrCache[$className] = [];
        $this->dbColumnToPropertyCache[$className] = [];

        foreach ($this->getProperties($className) as $prop) {
            $attrs = $prop->getAttributes(Column::class);
            if ($attrs === []) {
                continue;
            }
            $attrInstance = $attrs[0];
            /** @var Column $colAttr */
            $colAttr = $attrInstance->newInstance();
            $dbName  = $colAttr->name ?? $prop->getName();

            $this->columnAttrCache[$className][$prop->getName()]   = $attrInstance;
            $this->dbColumnToPropertyCache[$className][$dbName]    = $prop;
        }
    }

    private function resolvePhpType(\ReflectionProperty $property): string
    {
        $type = $property->getType();

        if ($type instanceof \ReflectionNamedType) {
            return $type->getName();
        }

        if ($type instanceof \ReflectionUnionType) {
            $types = array_filter(
                $type->getTypes(),
                fn($t) => $t instanceof \ReflectionNamedType && $t->getName() !== 'null',
            );
            $first = reset($types);
            return $first instanceof \ReflectionNamedType ? $first->getName() : 'mixed';
        }

        return 'mixed';
    }
}

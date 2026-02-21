<?php

declare(strict_types=1);

namespace Semitexa\Orm\Hydration;

use Semitexa\Orm\Attribute\Column;

class Hydrator
{
    private TypeCaster $typeCaster;

    /** @var array<string, \ReflectionProperty[]> Cached property reflections per class */
    private array $propertyCache = [];

    /** @var array<string, array<string, \ReflectionAttribute<Column>>> Column attributes cache */
    private array $columnAttrCache = [];

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
        $properties = $this->getProperties($resourceClass);

        foreach ($properties as $property) {
            $propertyName = $property->getName();

            if (!array_key_exists($propertyName, $row)) {
                continue;
            }

            $value = $row[$propertyName];
            $phpType = $this->resolvePhpType($property);
            $nullable = $property->getType() instanceof \ReflectionNamedType && $property->getType()->allowsNull();

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
        $properties = $this->getProperties($resourceClass);
        $columnAttrs = $this->getColumnAttributes($resourceClass);

        foreach ($properties as $property) {
            $propertyName = $property->getName();

            if (!isset($columnAttrs[$propertyName])) {
                continue;
            }

            if (!$property->isInitialized($resource)) {
                continue;
            }

            /** @var Column $column */
            $column = $columnAttrs[$propertyName]->newInstance();
            $value = $property->getValue($resource);

            $data[$propertyName] = $this->typeCaster->castToDb($value, new \Semitexa\Orm\Schema\ColumnDefinition(
                name: $propertyName,
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
     * @return array<string, \ReflectionAttribute<Column>>
     */
    private function getColumnAttributes(string $className): array
    {
        if (!isset($this->columnAttrCache[$className])) {
            $this->columnAttrCache[$className] = [];
            foreach ($this->getProperties($className) as $prop) {
                $attrs = $prop->getAttributes(Column::class);
                if ($attrs !== []) {
                    $this->columnAttrCache[$className][$prop->getName()] = $attrs[0];
                }
            }
        }

        return $this->columnAttrCache[$className];
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

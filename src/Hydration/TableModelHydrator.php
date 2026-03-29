<?php

declare(strict_types=1);

namespace Semitexa\Orm\Hydration;

use Semitexa\Orm\Metadata\ColumnMetadata;
use Semitexa\Orm\Metadata\TableModelMetadataRegistry;
use Semitexa\Orm\Schema\ColumnDefinition;

final class TableModelHydrator
{
    public function __construct(
        private readonly ?TypeCaster $typeCaster = null,
        private readonly ?TableModelMetadataRegistry $metadataRegistry = null,
    ) {}

    /**
     * @template T of object
     * @param array<string, mixed> $row
     * @param class-string<T> $tableModelClass
     * @return T
     */
    public function hydrate(array $row, string $tableModelClass): object
    {
        $metadata = ($this->metadataRegistry ?? TableModelMetadataRegistry::default())->for($tableModelClass);
        $typeCaster = $this->typeCaster ?? new TypeCaster();
        $ref = new \ReflectionClass($tableModelClass);
        $constructor = $ref->getConstructor();

        if ($constructor === null) {
            return new $tableModelClass();
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $parameterName = $parameter->getName();

            if ($metadata->hasColumn($parameterName)) {
                $column = $metadata->column($parameterName);
                $arguments[] = $this->hydrateColumnValue($row, $column, $parameter, $typeCaster);
                continue;
            }

            if ($metadata->hasRelation($parameterName)) {
                $arguments[] = $this->defaultRelationValue($parameter);
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            if ($parameter->allowsNull()) {
                $arguments[] = null;
                continue;
            }

            throw new \InvalidArgumentException(sprintf(
                'Cannot hydrate constructor parameter "%s" on %s.',
                $parameterName,
                $tableModelClass,
            ));
        }

        return $ref->newInstanceArgs($arguments);
    }

    /**
     * @return array<string, mixed>
     */
    public function dehydrate(object $tableModel): array
    {
        $metadata = ($this->metadataRegistry ?? TableModelMetadataRegistry::default())->for($tableModel::class);
        $typeCaster = $this->typeCaster ?? new TypeCaster();

        $data = [];
        foreach ($metadata->columns() as $column) {
            $property = new \ReflectionProperty($tableModel, $column->propertyName);
            if (!$property->isInitialized($tableModel)) {
                continue;
            }

            $data[$column->columnName] = $typeCaster->castToDb(
                $property->getValue($tableModel),
                $this->toColumnDefinition($column),
            );
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateColumnValue(
        array $row,
        ColumnMetadata $column,
        \ReflectionParameter $parameter,
        TypeCaster $typeCaster,
    ): mixed {
        if (!array_key_exists($column->columnName, $row)) {
            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }

            if ($column->nullable || $parameter->allowsNull()) {
                return null;
            }

            throw new \InvalidArgumentException(sprintf(
                'Missing required DB column "%s" for constructor parameter "%s".',
                $column->columnName,
                $parameter->getName(),
            ));
        }

        $value = $typeCaster->castFromDb($row[$column->columnName], $this->toColumnDefinition($column));

        return $typeCaster->castToPropertyType(
            $value,
            $column->phpType,
            $column->nullable || $parameter->allowsNull(),
        );
    }

    private function defaultRelationValue(\ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();
        if ($type instanceof \ReflectionNamedType && $type->getName() === RelationState::class) {
            return RelationState::notLoaded();
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($parameter->allowsNull()) {
            return null;
        }

        throw new \InvalidArgumentException(sprintf(
            'Relation parameter "%s" must either allow null, define a default, or use %s.',
            $parameter->getName(),
            RelationState::class,
        ));
    }

    private function toColumnDefinition(ColumnMetadata $column): ColumnDefinition
    {
        return new ColumnDefinition(
            name: $column->columnName,
            type: $column->type,
            phpType: $column->phpType,
            nullable: $column->nullable,
            length: $column->length,
            precision: $column->precision,
            scale: $column->scale,
            default: $column->default,
            isPrimaryKey: $column->isPrimaryKey,
            pkStrategy: $column->primaryKeyStrategy ?? 'auto',
            propertyName: $column->propertyName,
        );
    }
}

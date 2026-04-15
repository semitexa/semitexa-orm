<?php

declare(strict_types=1);

namespace Semitexa\Orm\Metadata;

use Semitexa\Orm\Attribute\BelongsTo;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\Connection;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\HasMany;
use Semitexa\Orm\Attribute\ManyToMany;
use Semitexa\Orm\Attribute\OneToOne;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Attribute\SoftDelete;
use Semitexa\Orm\Attribute\TenantScoped;

final class ResourceModelMetadataExtractor
{
    /**
     * @param class-string $resourceModelClass
     */
    public function extract(string $resourceModelClass): ResourceModelMetadata
    {
        $ref = new \ReflectionClass($resourceModelClass);
        $fromTableAttrs = $ref->getAttributes(FromTable::class);
        if ($fromTableAttrs === []) {
            throw new \InvalidArgumentException(sprintf(
                'Class %s has no #[FromTable] attribute.',
                $resourceModelClass,
            ));
        }

        /** @var FromTable $fromTable */
        $fromTable = $fromTableAttrs[0]->newInstance();

        $connectionAttrs = $ref->getAttributes(Connection::class);
        $connectionName = $connectionAttrs !== [] ? $connectionAttrs[0]->newInstance()->name : 'default';

        $columnsByProperty = [];
        $relationsByProperty = [];
        $primaryKeyProperty = null;

        foreach ($ref->getProperties() as $property) {
            $column = $this->extractColumn($property);
            if ($column !== null) {
                $columnsByProperty[$column->propertyName] = $column;
                if ($column->isPrimaryKey) {
                    $primaryKeyProperty = $column->propertyName;
                }
            }

            $relation = $this->extractRelation($property);
            if ($relation !== null) {
                $relationsByProperty[$relation->propertyName] = $relation;
            }
        }

        return new ResourceModelMetadata(
            className: $resourceModelClass,
            tableName: $fromTable->name,
            columnsByProperty: $columnsByProperty,
            relationsByProperty: $relationsByProperty,
            tenantPolicy: $this->extractTenantPolicy($ref),
            softDelete: $this->extractSoftDelete($ref, $columnsByProperty),
            primaryKeyProperty: $primaryKeyProperty,
            connectionName: $connectionName,
        );
    }

    private function extractColumn(\ReflectionProperty $property): ?ColumnMetadata
    {
        $columnAttrs = $property->getAttributes(Column::class);
        if ($columnAttrs === []) {
            return null;
        }

        /** @var Column $column */
        $column = $columnAttrs[0]->newInstance();
        $primaryKeyAttrs = $property->getAttributes(PrimaryKey::class);
        $primaryKey = $primaryKeyAttrs !== [] ? $primaryKeyAttrs[0]->newInstance() : null;

        return new ColumnMetadata(
            propertyName: $property->getName(),
            columnName: $column->name ?? $property->getName(),
            type: $column->type,
            phpType: $this->resolvePhpType($property),
            nullable: $column->nullable || $this->allowsNull($property),
            length: $column->length,
            precision: $column->precision,
            scale: $column->scale,
            default: $column->default,
            isPrimaryKey: $primaryKey !== null,
            primaryKeyStrategy: $primaryKey?->strategy,
        );
    }

    private function extractRelation(\ReflectionProperty $property): ?RelationMetadata
    {
        foreach ($property->getAttributes(BelongsTo::class) as $attr) {
            /** @var BelongsTo $relation */
            $relation = $attr->newInstance();
            /** @var class-string $targetClass */
            $targetClass = $relation->target;
            return new RelationMetadata(
                propertyName: $property->getName(),
                kind: RelationKind::BelongsTo,
                targetClass: $targetClass,
                foreignKey: $relation->foreignKey,
                onDelete: $relation->onDelete,
                onUpdate: $relation->onUpdate,
                writePolicy: $relation->writePolicy,
            );
        }

        foreach ($property->getAttributes(HasMany::class) as $attr) {
            /** @var HasMany $relation */
            $relation = $attr->newInstance();
            /** @var class-string $targetClass */
            $targetClass = $relation->target;
            return new RelationMetadata(
                propertyName: $property->getName(),
                kind: RelationKind::HasMany,
                targetClass: $targetClass,
                foreignKey: $relation->foreignKey,
                onDelete: $relation->onDelete,
                onUpdate: $relation->onUpdate,
                writePolicy: $relation->writePolicy,
            );
        }

        foreach ($property->getAttributes(OneToOne::class) as $attr) {
            /** @var OneToOne $relation */
            $relation = $attr->newInstance();
            /** @var class-string $targetClass */
            $targetClass = $relation->target;
            return new RelationMetadata(
                propertyName: $property->getName(),
                kind: RelationKind::OneToOne,
                targetClass: $targetClass,
                foreignKey: $relation->foreignKey,
                onDelete: $relation->onDelete,
                onUpdate: $relation->onUpdate,
                writePolicy: $relation->writePolicy,
            );
        }

        foreach ($property->getAttributes(ManyToMany::class) as $attr) {
            /** @var ManyToMany $relation */
            $relation = $attr->newInstance();
            /** @var class-string $targetClass */
            $targetClass = $relation->target;
            return new RelationMetadata(
                propertyName: $property->getName(),
                kind: RelationKind::ManyToMany,
                targetClass: $targetClass,
                foreignKey: $relation->foreignKey,
                pivotTable: $relation->pivotTable,
                relatedKey: $relation->relatedKey,
                onDelete: $relation->onDelete,
                onUpdate: $relation->onUpdate,
                writePolicy: $relation->writePolicy,
            );
        }

        return null;
    }

    /**
     * @param array<string, ColumnMetadata> $columnsByProperty
     */
    /**
     * @param \ReflectionClass<object> $ref
     * @param array<string, ColumnMetadata> $columnsByProperty
     */
    private function extractSoftDelete(\ReflectionClass $ref, array $columnsByProperty): ?SoftDeleteMetadata
    {
        $attrs = $ref->getAttributes(SoftDelete::class);
        if ($attrs === []) {
            return null;
        }

        /** @var SoftDelete $softDelete */
        $softDelete = $attrs[0]->newInstance();
        if (!isset($columnsByProperty[$softDelete->column])) {
            return new SoftDeleteMetadata(
                propertyName: $softDelete->column,
                columnName: $softDelete->column,
            );
        }

        $column = $columnsByProperty[$softDelete->column];

        return new SoftDeleteMetadata(
            propertyName: $column->propertyName,
            columnName: $column->columnName,
        );
    }

    /**
     * @param \ReflectionClass<object> $ref
     */
    private function extractTenantPolicy(\ReflectionClass $ref): ?TenantPolicyMetadata
    {
        $attrs = $ref->getAttributes(TenantScoped::class);
        if ($attrs === []) {
            return null;
        }

        /** @var TenantScoped $tenantScoped */
        $tenantScoped = $attrs[0]->newInstance();

        return new TenantPolicyMetadata(
            strategy: $tenantScoped->strategy,
            column: $tenantScoped->column,
        );
    }

    private function resolvePhpType(\ReflectionProperty $property): string
    {
        $type = $property->getType();

        if ($type instanceof \ReflectionNamedType) {
            return $type->getName();
        }

        if ($type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $namedType) {
                if ($namedType instanceof \ReflectionNamedType && $namedType->getName() !== 'null') {
                    return $namedType->getName();
                }
            }
        }

        return 'mixed';
    }

    private function allowsNull(\ReflectionProperty $property): bool
    {
        $type = $property->getType();
        return $type instanceof \ReflectionNamedType && $type->allowsNull();
    }
}

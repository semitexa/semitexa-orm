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
use Semitexa\Orm\Attribute\Replicated;
use Semitexa\Orm\Exception\InvalidResourceModelException;
use Semitexa\Orm\Domain\Enum\RelationWritePolicy;
use Semitexa\Orm\Exception\InvalidRelationDeclarationException;
use Semitexa\Orm\Attribute\SoftDelete;
use Semitexa\Orm\Attribute\Version;
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
                'Resource model %s is missing the required #[FromTable] attribute. '
                . 'Add #[FromTable(name: \'your_table\')] to the class so the ORM knows which table it maps to.',
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
        $versionProperty = null;

        foreach ($ref->getProperties() as $property) {
            $column = $this->extractColumn($property);
            if ($column !== null) {
                $columnsByProperty[$column->propertyName] = $column;
                if ($column->isPrimaryKey) {
                    $primaryKeyProperty = $column->propertyName;
                }
            }

            if ($property->getAttributes(Version::class) !== []) {
                if ($column === null) {
                    throw new \LogicException(sprintf(
                        '#[Version] on %s::$%s requires a #[Column] on the same property.',
                        $resourceModelClass,
                        $property->getName(),
                    ));
                }
                $type = $property->getType();
                if (!$type instanceof \ReflectionNamedType || $type->getName() !== 'int' || $type->allowsNull()) {
                    throw new \LogicException(sprintf(
                        '#[Version] on %s::$%s must be typed non-nullable int — the guard compares and increments it numerically.',
                        $resourceModelClass,
                        $property->getName(),
                    ));
                }
                if ($versionProperty !== null) {
                    throw new \LogicException(sprintf(
                        '%s declares more than one #[Version] property (%s and %s).',
                        $resourceModelClass,
                        $versionProperty,
                        $property->getName(),
                    ));
                }
                $versionProperty = $property->getName();
            }

            $relation = $this->extractRelation($property);
            if ($relation !== null) {
                $relationsByProperty[$relation->propertyName] = $relation;
            }
        }

        $this->assertNoCascadeOwnedReplicatedTarget($resourceModelClass, $relationsByProperty);

        $replicated = $ref->getAttributes(Replicated::class) !== [];
        if ($replicated) {
            $this->assertReplicable($resourceModelClass, $primaryKeyProperty, $columnsByProperty);
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
            versionProperty: $versionProperty,
            replicated: $replicated,
        );
    }

    /**
     * A CascadeOwned relation rewrites its children with one bulk DELETE and
     * fresh INSERTs. Only the inserts pass through the write engine, so for a
     * #[Replicated] child the deletes would never be captured: other nodes
     * would keep the old children and gain the new ones. Refused until
     * relations replicate as a whole (ADR 0001 §4).
     *
     * @param array<string, RelationMetadata> $relationsByProperty
     */
    private function assertNoCascadeOwnedReplicatedTarget(string $resourceModelClass, array $relationsByProperty): void
    {
        foreach ($relationsByProperty as $relation) {
            if ($relation->writePolicy !== RelationWritePolicy::CascadeOwned || !class_exists($relation->targetClass)) {
                continue;
            }
            if ((new \ReflectionClass($relation->targetClass))->getAttributes(Replicated::class) === []) {
                continue;
            }

            throw new InvalidRelationDeclarationException(sprintf(
                '%s::$%s is CascadeOwned but its target %s is #[Replicated]: cascade writes delete children in bulk, '
                . 'and those deletes are not captured for replication. Use a reference relation, or write the children '
                . 'through their own repository.',
                $resourceModelClass,
                $relation->propertyName,
                $relation->targetClass,
            ));
        }
    }

    /**
     * Every node creates rows on its own, so a replicated row's key must be
     * unique without asking anyone: an auto-increment id from two nodes would
     * name two different rows the same.
     *
     * @param array<string, ColumnMetadata> $columnsByProperty
     */
    private function assertReplicable(string $resourceModelClass, ?string $primaryKeyProperty, array $columnsByProperty): void
    {
        $strategy = $primaryKeyProperty !== null
            ? $columnsByProperty[$primaryKeyProperty]->primaryKeyStrategy
            : null;

        if ($strategy !== 'uuid') {
            throw new InvalidResourceModelException(sprintf(
                '#[Replicated] resource %s needs #[PrimaryKey(strategy: \'uuid\')]%s: nodes create rows independently, '
                . 'and only a UUIDv7 key cannot collide between them.',
                $resourceModelClass,
                $primaryKeyProperty === null ? '' : sprintf(' (it has \'%s\' on $%s)', (string) $strategy, $primaryKeyProperty),
            ));
        }
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

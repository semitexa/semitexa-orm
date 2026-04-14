<?php

declare(strict_types=1);

namespace Semitexa\Orm\Metadata;

use Semitexa\Orm\Exception\InvalidColumnDeclarationException;
use Semitexa\Orm\Exception\InvalidRelationDeclarationException;
use Semitexa\Orm\Exception\InvalidSoftDeleteDeclarationException;
use Semitexa\Orm\Exception\InvalidResourceModelException;
use Semitexa\Orm\Exception\InvalidTenantPolicyException;

final class ResourceModelMetadataValidator
{
    public function validate(ResourceModelMetadata $metadata): void
    {
        $ref = new \ReflectionClass($metadata->className);

        if (!$ref->isFinal()) {
            throw new InvalidResourceModelException(sprintf(
                'ResourceModel %s must be final.',
                $metadata->className,
            ));
        }

        if (!$ref->isReadOnly()) {
            throw new InvalidResourceModelException(sprintf(
                'ResourceModel %s must be readonly.',
                $metadata->className,
            ));
        }

        if ($metadata->columns() === []) {
            throw new InvalidColumnDeclarationException(sprintf(
                'ResourceModel %s must declare at least one #[Column].',
                $metadata->className,
            ));
        }

        $primaryKeys = array_filter(
            $metadata->columns(),
            static fn (ColumnMetadata $column): bool => $column->isPrimaryKey,
        );
        if (count($primaryKeys) !== 1) {
            throw new InvalidColumnDeclarationException(sprintf(
                'ResourceModel %s must declare exactly one #[PrimaryKey] column.',
                $metadata->className,
            ));
        }

        foreach ($metadata->relations() as $relation) {
            if (!class_exists($relation->targetClass)) {
                throw new InvalidRelationDeclarationException(sprintf(
                    'Relation %s::$%s targets missing class %s.',
                    $metadata->className,
                    $relation->propertyName,
                    $relation->targetClass,
                ));
            }

            if ($relation->writePolicy === null) {
                throw new InvalidRelationDeclarationException(sprintf(
                    'Relation %s::$%s must declare a write policy.',
                    $metadata->className,
                    $relation->propertyName,
                ));
            }

            if (
                $relation->kind === RelationKind::ManyToMany
                && ($relation->pivotTable === null || $relation->relatedKey === null)
            ) {
                throw new InvalidRelationDeclarationException(sprintf(
                    'ManyToMany relation %s::$%s must define pivotTable and relatedKey.',
                    $metadata->className,
                    $relation->propertyName,
                ));
            }

            if ($relation->kind === RelationKind::ManyToMany && $relation->writePolicy !== \Semitexa\Orm\Persistence\RelationWritePolicy::SyncPivotOnly) {
                throw new InvalidRelationDeclarationException(sprintf(
                    'ManyToMany relation %s::$%s must use %s.',
                    $metadata->className,
                    $relation->propertyName,
                    \Semitexa\Orm\Persistence\RelationWritePolicy::SyncPivotOnly->name,
                ));
            }

            if (
                $relation->kind !== RelationKind::ManyToMany
                && $relation->writePolicy === \Semitexa\Orm\Persistence\RelationWritePolicy::SyncPivotOnly
            ) {
                throw new InvalidRelationDeclarationException(sprintf(
                    'Relation %s::$%s can use %s only for ManyToMany associations.',
                    $metadata->className,
                    $relation->propertyName,
                    \Semitexa\Orm\Persistence\RelationWritePolicy::SyncPivotOnly->name,
                ));
            }
        }

        if ($metadata->tenantPolicy !== null) {
            if ($metadata->tenantPolicy->column === null || $metadata->tenantPolicy->column === '') {
                throw new InvalidTenantPolicyException(sprintf(
                    'Tenant policy on %s must declare a tenant column.',
                    $metadata->className,
                ));
            }

            $tenantColumn = $metadata->tenantPolicy->column;
            $matchesDeclaredColumn = $metadata->hasColumn($tenantColumn)
                || $this->hasDeclaredColumnName($metadata, $tenantColumn);

            if (!$matchesDeclaredColumn) {
                throw new InvalidTenantPolicyException(sprintf(
                    'Tenant policy column "%s" is not a declared column on %s.',
                    $tenantColumn,
                    $metadata->className,
                ));
            }
        }

        if ($metadata->softDelete !== null) {
            if (!$metadata->hasColumn($metadata->softDelete->propertyName)) {
                throw new InvalidSoftDeleteDeclarationException(sprintf(
                    'Soft-delete column "%s" is not a declared column on %s.',
                    $metadata->softDelete->propertyName,
                    $metadata->className,
                ));
            }

            $column = $metadata->column($metadata->softDelete->propertyName);
            if (!$column->nullable) {
                throw new InvalidSoftDeleteDeclarationException(sprintf(
                    'Soft-delete column %s::$%s must be nullable.',
                    $metadata->className,
                    $metadata->softDelete->propertyName,
                ));
            }
        }
    }

    private function hasDeclaredColumnName(ResourceModelMetadata $metadata, string $columnName): bool
    {
        foreach ($metadata->columns() as $column) {
            if ($column->columnName === $columnName) {
                return true;
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Orm\Metadata;

final readonly class ResourceModelMetadata
{
    /**
     * @param array<string, ColumnMetadata> $columnsByProperty
     * @param array<string, RelationMetadata> $relationsByProperty
     */
    public function __construct(
        public string $className,
        public string $tableName,
        public array $columnsByProperty,
        public array $relationsByProperty,
        public ?TenantPolicyMetadata $tenantPolicy = null,
        public ?SoftDeleteMetadata $softDelete = null,
        public ?string $primaryKeyProperty = null,
        public string $connectionName = 'default',
    ) {}

    /**
     * @return array<string, ColumnMetadata>
     */
    public function columns(): array
    {
        return $this->columnsByProperty;
    }

    public function hasColumn(string $propertyName): bool
    {
        return isset($this->columnsByProperty[$propertyName]);
    }

    public function column(string $propertyName): ColumnMetadata
    {
        if (!isset($this->columnsByProperty[$propertyName])) {
            throw new \InvalidArgumentException(sprintf(
                'Column metadata for property "%s" is not defined on %s.',
                $propertyName,
                $this->className,
            ));
        }

        return $this->columnsByProperty[$propertyName];
    }

    /**
     * @return array<string, RelationMetadata>
     */
    public function relations(): array
    {
        return $this->relationsByProperty;
    }

    public function hasRelation(string $propertyName): bool
    {
        return isset($this->relationsByProperty[$propertyName]);
    }

    public function relation(string $propertyName): RelationMetadata
    {
        if (!isset($this->relationsByProperty[$propertyName])) {
            throw new \InvalidArgumentException(sprintf(
                'Relation metadata for property "%s" is not defined on %s.',
                $propertyName,
                $this->className,
            ));
        }

        return $this->relationsByProperty[$propertyName];
    }
}

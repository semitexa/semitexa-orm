<?php

declare(strict_types=1);

namespace Semitexa\Orm\Query;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Hydration\TableModelHydrator;
use Semitexa\Orm\Hydration\TableModelRelationLoader;
use Semitexa\Orm\Hydration\TypeCaster;
use Semitexa\Orm\Mapping\MapperRegistry;
use Semitexa\Orm\Metadata\ColumnRef;
use Semitexa\Orm\Metadata\ColumnMetadata;
use Semitexa\Orm\Metadata\RelationRef;
use Semitexa\Orm\Metadata\TableModelMetadataRegistry;
use Semitexa\Orm\Schema\ColumnDefinition;

final class TableModelQuery
{
    /** @var array<int, array{type: 'comparison', column: ColumnRef, operator: Operator, value: mixed}|array{type: 'null', column: ColumnRef, negated: bool}> */
    private array $wheres = [];

    /** @var list<RelationRef> */
    private array $relations = [];

    /** @var array<int, array{column: ColumnRef, direction: Direction}> */
    private array $orderBys = [];

    private ?int $limitValue = null;
    private ?int $offsetValue = null;
    private mixed $tenantValue = null;
    private bool $includeSoftDeleted = false;
    private bool $onlySoftDeleted = false;
    private bool $skipTenantScope = false;

    public function __construct(
        private readonly string $tableModelClass,
        private readonly DatabaseAdapterInterface $adapter,
        private readonly TableModelHydrator $hydrator,
        private readonly TableModelRelationLoader $relationLoader,
        private readonly ?TableModelMetadataRegistry $metadataRegistry = null,
        private readonly ?TypeCaster $typeCaster = null,
    ) {}

    public function where(ColumnRef $column, Operator $operator, mixed $value): self
    {
        $this->assertColumnBelongsToCurrentTableModel($column);
        $this->wheres[] = [
            'type' => 'comparison',
            'column' => $column,
            'operator' => $operator,
            'value' => $this->normalizeComparisonValue($column, $value),
        ];

        return $this;
    }

    public function whereNull(ColumnRef $column): self
    {
        $this->assertColumnBelongsToCurrentTableModel($column);
        $this->wheres[] = [
            'type' => 'null',
            'column' => $column,
            'negated' => false,
        ];

        return $this;
    }

    public function whereNotNull(ColumnRef $column): self
    {
        $this->assertColumnBelongsToCurrentTableModel($column);
        $this->wheres[] = [
            'type' => 'null',
            'column' => $column,
            'negated' => true,
        ];

        return $this;
    }

    public function withRelation(RelationRef $relation): self
    {
        $this->assertRelationBelongsToCurrentTableModel($relation);
        $this->relations[] = $relation;

        return $this;
    }

    public function orderBy(ColumnRef $column, Direction $direction): self
    {
        $this->assertColumnBelongsToCurrentTableModel($column);
        $this->orderBys[] = [
            'column' => $column,
            'direction' => $direction,
        ];

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limitValue = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offsetValue = $offset;
        return $this;
    }

    public function forTenant(mixed $tenantValue): self
    {
        $this->tenantValue = $tenantValue;

        return $this;
    }

    public function includeSoftDeleted(): self
    {
        $this->includeSoftDeleted = true;
        $this->onlySoftDeleted = false;

        return $this;
    }

    public function onlySoftDeleted(): self
    {
        $this->includeSoftDeleted = false;
        $this->onlySoftDeleted = true;

        return $this;
    }

    public function withoutTenantScope(SystemScopeToken $token): self
    {
        $this->skipTenantScope = true;

        return $this;
    }

    /**
     * @return list<object>
     */
    public function fetchAll(): array
    {
        $result = $this->adapter->execute($this->buildSql(), $this->buildParams());
        $items = array_map(
            fn (array $row): object => $this->hydrator->hydrate($row, $this->tableModelClass),
            $result->rows,
        );

        if ($items !== [] && $this->relations !== []) {
            $this->relationLoader->loadRelations(
                $items,
                $this->tableModelClass,
                array_map(static fn (RelationRef $relation): string => $relation->propertyName, $this->relations),
            );
        }

        return $items;
    }

    public function fetchOne(): ?object
    {
        $items = $this->limit(1)->fetchAll();

        return $items[0] ?? null;
    }

    /**
     * @return list<object>
     */
    public function fetchAllAs(string $domainModelClass, MapperRegistry $mapperRegistry): array
    {
        return array_map(
            fn (object $tableModel): object => $mapperRegistry->mapToDomain($tableModel, $domainModelClass),
            $this->fetchAll(),
        );
    }

    public function fetchOneAs(string $domainModelClass, MapperRegistry $mapperRegistry): ?object
    {
        $item = $this->fetchOne();

        if ($item === null) {
            return null;
        }

        return $mapperRegistry->mapToDomain($item, $domainModelClass);
    }

    public function toSql(): string
    {
        return $this->buildSql();
    }

    /**
     * @return array<string, mixed>
     */
    public function toParams(): array
    {
        return $this->buildParams();
    }

    private function buildSql(): string
    {
        $metadata = ($this->metadataRegistry ?? TableModelMetadataRegistry::default())->for($this->tableModelClass);
        $this->assertRequiredPoliciesAreSatisfied($metadata);
        $sql = sprintf('SELECT * FROM `%s`', $metadata->tableName);
        $conditions = [];

        if ($metadata->tenantPolicy !== null && !$this->skipTenantScope) {
            $tenantColumn = $this->resolveTenantColumnName($metadata);
            $conditions[] = sprintf('`%s` = :tenant_scope', $tenantColumn);
        }

        if ($metadata->softDelete !== null) {
            if ($this->onlySoftDeleted) {
                $conditions[] = sprintf('`%s` IS NOT NULL', $metadata->softDelete->columnName);
            } elseif (!$this->includeSoftDeleted) {
                $conditions[] = sprintf('`%s` IS NULL', $metadata->softDelete->columnName);
            }
        }

        if ($this->wheres !== []) {
            foreach ($this->wheres as $index => $where) {
                if ($where['type'] === 'null') {
                    $conditions[] = sprintf(
                        '`%s` IS %sNULL',
                        $where['column']->columnName,
                        $where['negated'] ? 'NOT ' : '',
                    );
                    continue;
                }

                $param = sprintf(':w%d', $index);
                $conditions[] = sprintf(
                    '`%s` %s %s',
                    $where['column']->columnName,
                    $where['operator']->value,
                    $param,
                );
            }
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        if ($this->orderBys !== []) {
            $parts = [];
            foreach ($this->orderBys as $orderBy) {
                $parts[] = sprintf(
                    '`%s` %s',
                    $orderBy['column']->columnName,
                    $orderBy['direction']->value,
                );
            }
            $sql .= ' ORDER BY ' . implode(', ', $parts);
        }

        if ($this->limitValue !== null) {
            $sql .= sprintf(' LIMIT %d', $this->limitValue);
        }

        if ($this->offsetValue !== null) {
            $sql .= sprintf(' OFFSET %d', $this->offsetValue);
        }

        return $sql;
    }

    private function resolveTenantColumnName(\Semitexa\Orm\Metadata\TableModelMetadata $metadata): string
    {
        $tenantColumn = $metadata->tenantPolicy->column ?? 'tenantId';

        if ($metadata->hasColumn($tenantColumn)) {
            return $metadata->column($tenantColumn)->columnName;
        }

        return $tenantColumn;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildParams(): array
    {
        $params = [];

        $metadata = ($this->metadataRegistry ?? TableModelMetadataRegistry::default())->for($this->tableModelClass);
        if ($metadata->tenantPolicy !== null && !$this->skipTenantScope) {
            $params['tenant_scope'] = $this->tenantValue;
        }

        foreach ($this->wheres as $index => $where) {
            if ($where['type'] !== 'comparison') {
                continue;
            }

            $params[sprintf('w%d', $index)] = $where['value'];
        }

        return $params;
    }

    private function assertColumnBelongsToCurrentTableModel(ColumnRef $column): void
    {
        if ($column->tableModelClass !== $this->tableModelClass) {
            throw new \InvalidArgumentException(sprintf(
                'ColumnRef for %s cannot be used in query for %s.',
                $column->tableModelClass,
                $this->tableModelClass,
            ));
        }
    }

    private function assertRelationBelongsToCurrentTableModel(RelationRef $relation): void
    {
        if ($relation->tableModelClass !== $this->tableModelClass) {
            throw new \InvalidArgumentException(sprintf(
                'RelationRef for %s cannot be used in query for %s.',
                $relation->tableModelClass,
                $this->tableModelClass,
            ));
        }
    }

    private function assertRequiredPoliciesAreSatisfied(\Semitexa\Orm\Metadata\TableModelMetadata $metadata): void
    {
        if ($metadata->tenantPolicy !== null && !$this->skipTenantScope && $this->tenantValue === null) {
            throw new \LogicException(sprintf(
                'Query for tenant-scoped table model %s requires tenant context. Call forTenant() or withoutTenantScope().',
                $this->tableModelClass,
            ));
        }
    }

    private function normalizeComparisonValue(ColumnRef $column, mixed $value): mixed
    {
        $metadata = ($this->metadataRegistry ?? TableModelMetadataRegistry::default())->for($this->tableModelClass);
        $columnMetadata = $metadata->column($column->propertyName);
        $typeCaster = $this->typeCaster ?? new TypeCaster();

        return $typeCaster->castToDb(
            $value instanceof \BackedEnum ? $value->value : $value,
            $this->toColumnDefinition($columnMetadata),
        );
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

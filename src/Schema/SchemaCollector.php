<?php

declare(strict_types=1);

namespace Semitexa\Orm\Schema;

use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Aggregate;
use Semitexa\Orm\Attribute\BelongsTo;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\Deprecated;
use Semitexa\Orm\Attribute\Filterable;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\HasMany;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Attribute\ManyToMany;
use Semitexa\Orm\Attribute\OneToOne;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Attribute\TenantScoped;
use Semitexa\Orm\Contract\DomainMappable;

class SchemaCollector
{
    /** @var string[] */
    private array $errors = [];

    /** @var string[] */
    private array $warnings = [];

    /**
     * @return array<string, TableDefinition>
     */
    public function collect(): array
    {
        $this->errors = [];
        $this->warnings = [];

        $classes = ClassDiscovery::findClassesWithAttribute(FromTable::class);
        /** @var array<string, TableDefinition> $tables */
        $tables = [];

        foreach ($classes as $className) {
            $this->processClass($className, $tables);
        }

        $this->addPivotTables($classes, $tables);

        foreach ($tables as $table) {
            $tableErrors = $table->validate();
            foreach ($tableErrors as $error) {
                $this->warnings[] = $error;
            }
        }

        $this->resolveForeignKeys($tables);

        return $tables;
    }

    /** @return string[] */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /** @return string[] */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * @param array<string, TableDefinition> $tables
     */
    private function processClass(string $className, array &$tables): void
    {
        $ref = new \ReflectionClass($className);
        $fromTableAttrs = $ref->getAttributes(FromTable::class);

        if (empty($fromTableAttrs)) {
            return;
        }

        /** @var FromTable $fromTable */
        $fromTable = $fromTableAttrs[0]->newInstance();
        $tableName = $fromTable->name;

        $this->assertValidIdentifier($tableName, "table name in '{$className}'");

        if ($fromTable->mapTo !== null && !is_subclass_of($className, DomainMappable::class)) {
            $this->errors[] = "Class '{$className}' has mapTo='{$fromTable->mapTo}' but does not implement DomainMappable.";
        }

        if (!isset($tables[$tableName])) {
            $tables[$tableName] = new TableDefinition($tableName);
        }

        $table = $tables[$tableName];

        // Collect properties from the class and its traits
        $properties = $this->getAllProperties($ref);

        foreach ($properties as $property) {
            $this->processProperty($property, $table, $className);
        }

        // Collect class-level Index attributes
        $indexAttrs = $ref->getAttributes(Index::class);
        foreach ($indexAttrs as $indexAttr) {
            /** @var Index $index */
            $index = $indexAttr->newInstance();
            $table->addIndex(new IndexDefinition(
                columns: $index->columns,
                unique: $index->unique,
                name: $index->name,
            ));
        }

        // TenantScoped(same_storage): ensure tenant_id column exists for migrations
        $tenantAttrs = $ref->getAttributes(TenantScoped::class);
        if ($tenantAttrs !== []) {
            /** @var TenantScoped $tenantAttr */
            $tenantAttr = $tenantAttrs[0]->newInstance();
            $tenantColumn = 'tenant_id';
            if ($tenantAttr->strategy === 'same_storage' && $table->getColumn($tenantColumn) === null) {
                $table->addColumn(new ColumnDefinition(
                    name: $tenantColumn,
                    type: MySqlType::Varchar,
                    phpType: 'string',
                    nullable: false,
                    length: 64,
                    propertyName: 'tenantId',
                ));
            }
        }
    }

    /**
     * @return \ReflectionProperty[]
     */
    private function getAllProperties(\ReflectionClass $ref): array
    {
        $properties = $ref->getProperties();
        $seen = [];
        $result = [];

        foreach ($properties as $prop) {
            if (!isset($seen[$prop->getName()])) {
                $seen[$prop->getName()] = true;
                $result[] = $prop;
            }
        }

        return $result;
    }

    private function processProperty(\ReflectionProperty $property, TableDefinition $table, string $className): void
    {
        $columnAttrs = $property->getAttributes(Column::class);
        $pkAttrs = $property->getAttributes(PrimaryKey::class);
        $deprecatedAttrs = $property->getAttributes(Deprecated::class);

        // Relation attributes
        $this->processRelations($property, $table);

        // Aggregate — no column processing needed
        if (!empty($property->getAttributes(Aggregate::class))) {
            return;
        }

        if (empty($columnAttrs)) {
            // Skip internal/trait state (e.g. FilterableTrait __filterCriteria)
            if (str_starts_with($property->getName(), '__')) {
                return;
            }
            // Property without #[Column] in a Resource class — error unless it's a relation
            if (empty($property->getAttributes(BelongsTo::class))
                && empty($property->getAttributes(HasMany::class))
                && empty($property->getAttributes(OneToOne::class))
                && empty($property->getAttributes(ManyToMany::class))
                && empty($property->getAttributes(Aggregate::class))
            ) {
                $this->errors[] = "Property '{$property->getName()}' in '{$className}' has no #[Column] attribute.";
            }
            return;
        }

        /** @var Column $column */
        $column = $columnAttrs[0]->newInstance();

        $isPrimaryKey = !empty($pkAttrs);
        $pkStrategy = 'auto';
        if ($isPrimaryKey) {
            /** @var PrimaryKey $pk */
            $pk = $pkAttrs[0]->newInstance();
            $pkStrategy = $pk->strategy;

            // string PK without explicit strategy
            $phpType = $this->resolvePhpType($property);
            if ($phpType === 'string' && $pkStrategy === 'auto') {
                $this->errors[] = "Property '{$property->getName()}' in '{$className}': string primary key requires explicit strategy.";
            }

            // uuid strategy requires Binary or Varchar column type
            if ($pkStrategy === 'uuid' && !empty($columnAttrs)) {
                /** @var Column $colCheck */
                $colCheck = $columnAttrs[0]->newInstance();
                if ($colCheck->type !== MySqlType::Binary && $colCheck->type !== MySqlType::Varchar) {
                    $this->errors[] = "Property '{$property->getName()}' in '{$className}': uuid strategy requires Binary or Varchar column type.";
                }
            }
        }

        $isDeprecated = !empty($deprecatedAttrs);
        $phpType = $this->resolvePhpType($property);

        $propertyName = $property->getName();
        $columnName   = $column->name ?? $propertyName;

        // Validate PHP type vs MySqlType
        $this->validateTypeMatch($phpType, $column->type, $propertyName, $className);

        $nullable = $column->nullable || ($property->getType() instanceof \ReflectionNamedType && $property->getType()->allowsNull());

        $this->assertValidIdentifier($columnName, "column '{$columnName}' in '{$className}'");

        $colDef = new ColumnDefinition(
            name: $columnName,
            type: $column->type,
            phpType: $phpType,
            nullable: $nullable,
            length: $column->length,
            precision: $column->precision,
            scale: $column->scale,
            default: $column->default,
            isPrimaryKey: $isPrimaryKey,
            pkStrategy: $pkStrategy,
            isDeprecated: $isDeprecated,
            propertyName: $propertyName,
        );

        // Multiple Resources can map to the same table (e.g. base + projection); merge schema — skip column if already defined
        if ($table->getColumn($columnName) !== null) {
            return;
        }

        $table->addColumn($colDef);

        // Auto-index for #[Filterable] columns (single-column, non-unique)
        if (!empty($property->getAttributes(Filterable::class))) {
            $indexName = 'idx_' . $table->name . '_' . $columnName;
            $table->addIndex(new IndexDefinition(
                columns: [$columnName],
                unique: false,
                name: $indexName,
            ));
        }

        if ($isDeprecated) {
            $this->checkDeprecatedUsage($columnName, $table);
        }
    }

    private function processRelations(\ReflectionProperty $property, TableDefinition $table): void
    {
        $propName = $property->getName();

        foreach ($property->getAttributes(BelongsTo::class) as $attr) {
            /** @var BelongsTo $rel */
            $rel = $attr->newInstance();
            $table->addRelation($propName, 'belongs_to', $rel->target, $rel->foreignKey, null, null, $rel->onDelete, $rel->onUpdate);
        }

        foreach ($property->getAttributes(HasMany::class) as $attr) {
            /** @var HasMany $rel */
            $rel = $attr->newInstance();
            $table->addRelation($propName, 'has_many', $rel->target, $rel->foreignKey, null, null, $rel->onDelete, $rel->onUpdate);
        }

        foreach ($property->getAttributes(OneToOne::class) as $attr) {
            /** @var OneToOne $rel */
            $rel = $attr->newInstance();
            $table->addRelation($propName, 'one_to_one', $rel->target, $rel->foreignKey, null, null, $rel->onDelete, $rel->onUpdate);
        }

        foreach ($property->getAttributes(ManyToMany::class) as $attr) {
            /** @var ManyToMany $rel */
            $rel = $attr->newInstance();
            $table->addRelation($propName, 'many_to_many', $rel->target, $rel->foreignKey, $rel->pivotTable, $rel->relatedKey, $rel->onDelete, $rel->onUpdate);
        }
    }

    private function resolvePhpType(\ReflectionProperty $property): string
    {
        $type = $property->getType();

        if ($type instanceof \ReflectionNamedType) {
            return $type->getName();
        }

        if ($type instanceof \ReflectionUnionType) {
            $types = array_map(
                fn(\ReflectionNamedType $t) => $t->getName(),
                array_filter($type->getTypes(), fn($t) => $t instanceof \ReflectionNamedType && $t->getName() !== 'null'),
            );
            return $types[0] ?? 'mixed';
        }

        return 'mixed';
    }

    private function validateTypeMatch(string $phpType, MySqlType $sqlType, string $propName, string $className): void
    {
        // Backed enums: StringBackedEnum → Varchar/Text, IntBackedEnum → Int/Bigint
        if (enum_exists($phpType)) {
            $ref = new \ReflectionEnum($phpType);
            if ($ref->isBacked()) {
                $backingType = (string) $ref->getBackingType();
                $phpType = $backingType; // Replace enum class name with its backing type
            } else {
                $this->errors[] = "Property '{$propName}' in '{$className}': non-backed enum '{$phpType}' cannot be mapped to a database column.";
                return;
            }
        }

        $valid = match ($sqlType) {
            MySqlType::Varchar, MySqlType::Char,
            MySqlType::Text, MySqlType::MediumText,
            MySqlType::LongText, MySqlType::Time        => in_array($phpType, ['string', 'mixed']),
            MySqlType::Json                             => in_array($phpType, ['string', 'array', 'mixed']),
            MySqlType::TinyInt, MySqlType::SmallInt,
            MySqlType::Int, MySqlType::Bigint,
            MySqlType::Year                             => in_array($phpType, ['int', 'mixed']),
            MySqlType::Float, MySqlType::Double         => in_array($phpType, ['float', 'mixed']),
            MySqlType::Decimal                          => in_array($phpType, ['string', 'float', 'mixed']),
            MySqlType::Boolean                          => in_array($phpType, ['bool', 'int', 'mixed']),
            MySqlType::Datetime, MySqlType::Timestamp,
            MySqlType::Date                             => in_array($phpType, ['DateTimeImmutable', 'DateTime', 'string', 'mixed']),
            MySqlType::Blob, MySqlType::Binary          => in_array($phpType, ['string', 'mixed']),
        };

        if (!$valid) {
            $this->errors[] = "Property '{$propName}' in '{$className}': PHP type '{$phpType}' is incompatible with MySQL type '{$sqlType->value}'.";
        }
    }

    /**
     * Validate that a SQL identifier (table or column name) contains only
     * safe characters: letters, digits, and underscores, starting with a
     * letter or underscore. Identifiers come from PHP attributes, not user
     * input, but a typo or malformed name would silently produce broken SQL.
     * Throwing early gives a clear error at schema-collect time.
     */
    private function assertValidIdentifier(string $name, string $context): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException(
                "Invalid SQL identifier for {$context}: '{$name}'. "
                . "Only letters, digits and underscores are allowed, starting with a letter or underscore."
            );
        }
    }

    /**
     * Add synthetic table definitions for ManyToMany pivot tables so they are created by orm:sync.
     *
     * @param list<string> $classes FromTable class names
     * @param array<string, TableDefinition> $tables
     */
    private function addPivotTables(array $classes, array &$tables): void
    {
        $classToTable = [];
        foreach ($classes as $className) {
            try {
                $ref = new \ReflectionClass($className);
                $attrs = $ref->getAttributes(FromTable::class);
                if (!empty($attrs)) {
                    /** @var FromTable $ft */
                    $ft = $attrs[0]->newInstance();
                    $classToTable[$className] = $ft->name;
                }
            } catch (\ReflectionException) {
                // skip
            }
        }

        foreach ($tables as $tableName => $table) {
            foreach ($table->getRelations() as $relation) {
                if (($relation['type'] ?? '') !== 'many_to_many') {
                    continue;
                }
                $pivotTable = $relation['pivotTable'] ?? null;
                $foreignKey = $relation['foreignKey'] ?? null;
                $relatedKey = $relation['relatedKey'] ?? null;
                $targetClass = $relation['target'] ?? null;
                if ($pivotTable === null || $foreignKey === null || $relatedKey === null || $targetClass === null) {
                    continue;
                }
                if (isset($tables[$pivotTable])) {
                    continue; // already added (e.g. same pivot from another side)
                }
                $this->assertValidIdentifier($pivotTable, "pivot table '{$pivotTable}'");
                $targetTable = $classToTable[$targetClass] ?? null;
                if ($targetTable === null) {
                    try {
                        $ref = new \ReflectionClass($targetClass);
                        $attrs = $ref->getAttributes(FromTable::class);
                        if (!empty($attrs)) {
                            $ft = $attrs[0]->newInstance();
                            $targetTable = $ft->name;
                        }
                    } catch (\ReflectionException) {
                        // skip
                    }
                }
                if ($targetTable === null) {
                    continue;
                }
                $pivot = new TableDefinition($pivotTable);
                $pivot->addColumn(new ColumnDefinition(
                    name: 'id',
                    type: MySqlType::Int,
                    phpType: 'int',
                    nullable: false,
                    isPrimaryKey: true,
                    pkStrategy: 'auto',
                ));
                $pivot->addColumn(new ColumnDefinition(
                    name: $foreignKey,
                    type: MySqlType::Int,
                    phpType: 'int',
                    nullable: false,
                ));
                $pivot->addColumn(new ColumnDefinition(
                    name: $relatedKey,
                    type: MySqlType::Int,
                    phpType: 'int',
                    nullable: false,
                ));
                $pivot->addIndex(new IndexDefinition(
                    columns: [$foreignKey, $relatedKey],
                    unique: true,
                    name: 'uniq_' . $foreignKey . '_' . $relatedKey,
                ));
                $tables[$pivotTable] = $pivot;
            }
        }
    }

    /**
     * Post-process step: build ForeignKeyDefinition objects for all relations
     * that carry a FK column on the owning side.
     *
     * BelongsTo — FK lives on THIS table → generate FK from this table → target.
     * HasMany    — FK lives on the CHILD table → generate FK from target table → this table.
     * OneToOne   — FK lives on the CHILD/related table → same as HasMany.
     * ManyToMany — FK lives on the pivot table → add FKs from pivot to parent and target.
     *
     * @param array<string, TableDefinition> $tables
     */
    private function resolveForeignKeys(array $tables): void
    {
        // Build a map: table name → PK column name (for reference resolution)
        $pkMap = [];
        foreach ($tables as $tableName => $table) {
            $pk = $table->getPrimaryKey();
            if ($pk !== null) {
                $pkMap[$tableName] = $pk->name;
            }
        }

        // Build a map: resource class name → table name
        $classToTable = [];
        foreach ($tables as $tableName => $table) {
            foreach ($table->getRelations() as $relation) {
                $targetClass = $relation['target'];
                if (!isset($classToTable[$targetClass])) {
                    try {
                        $ref = new \ReflectionClass($targetClass);
                        $attrs = $ref->getAttributes(FromTable::class);
                        if (!empty($attrs)) {
                            /** @var \Semitexa\Orm\Attribute\FromTable $ft */
                            $ft = $attrs[0]->newInstance();
                            $classToTable[$targetClass] = $ft->name;
                        }
                    } catch (\ReflectionException) {
                        // skip
                    }
                }
            }
        }

        foreach ($tables as $tableName => $table) {
            foreach ($table->getRelations() as $relation) {
                $type       = $relation['type'];
                $targetClass = $relation['target'];
                $foreignKey = $relation['foreignKey'];
                $onDelete   = $relation['onDelete'] ?? null;
                $onUpdate   = $relation['onUpdate'] ?? null;

                /** @var string|null $onDelete */
                /** @var string|null $onUpdate */
                $targetTable = $classToTable[$targetClass] ?? null;
                if ($targetTable === null) {
                    continue;
                }

                if ($type === 'belongs_to') {
                    // FK is on THIS table — references the target's PK
                    $referencedPk = $pkMap[$targetTable] ?? 'id';
                    $fkCol = $table->getColumn($foreignKey);
                    $nullable = $fkCol !== null && $fkCol->nullable;
                    $resolvedOnDelete = $onDelete ?? ($nullable ? ForeignKeyAction::SetNull : ForeignKeyAction::Restrict);
                    $resolvedOnUpdate = $onUpdate ?? ($nullable ? ForeignKeyAction::SetNull : ForeignKeyAction::Restrict);

                    $table->addForeignKey(new ForeignKeyDefinition(
                        table: $tableName,
                        column: $foreignKey,
                        referencedTable: $targetTable,
                        referencedColumn: $referencedPk,
                        onDelete: $resolvedOnDelete,
                        onUpdate: $resolvedOnUpdate,
                    ));
                } elseif ($type === 'has_many' || $type === 'one_to_one') {
                    // FK is on the TARGET table — references THIS table's PK
                    $referencedPk = $pkMap[$tableName] ?? 'id';
                    $targetTableDef = $tables[$targetTable] ?? null;
                    if ($targetTableDef === null) {
                        continue;
                    }
                    $fkCol = $targetTableDef->getColumn($foreignKey);
                    $nullable = $fkCol !== null && $fkCol->nullable;
                    $resolvedOnDelete = $onDelete ?? ($nullable ? ForeignKeyAction::SetNull : ForeignKeyAction::Restrict);
                    $resolvedOnUpdate = $onUpdate ?? ($nullable ? ForeignKeyAction::SetNull : ForeignKeyAction::Restrict);

                    $targetTableDef->addForeignKey(new ForeignKeyDefinition(
                        table: $targetTable,
                        column: $foreignKey,
                        referencedTable: $tableName,
                        referencedColumn: $referencedPk,
                        onDelete: $resolvedOnDelete,
                        onUpdate: $resolvedOnUpdate,
                    ));
                } elseif ($type === 'many_to_many') {
                    // FK is on the pivot table: pivot.foreignKey -> this table, pivot.relatedKey -> target table
                    $pivotTable = $relation['pivotTable'] ?? null;
                    $relatedKey = $relation['relatedKey'] ?? null;
                    if ($pivotTable === null || $relatedKey === null) {
                        continue;
                    }
                    $pivotDef = $tables[$pivotTable] ?? null;
                    if ($pivotDef === null) {
                        continue;
                    }
                    $parentPk = $pkMap[$tableName] ?? 'id';
                    $targetPk = $pkMap[$targetTable] ?? 'id';
                    $restrict = ForeignKeyAction::Restrict;
                    $pivotDef->addForeignKey(new ForeignKeyDefinition(
                        table: $pivotTable,
                        column: $foreignKey,
                        referencedTable: $tableName,
                        referencedColumn: $parentPk,
                        onDelete: $onDelete ?? $restrict,
                        onUpdate: $onUpdate ?? $restrict,
                    ));
                    $pivotDef->addForeignKey(new ForeignKeyDefinition(
                        table: $pivotTable,
                        column: $relatedKey,
                        referencedTable: $targetTable,
                        referencedColumn: $targetPk,
                        onDelete: $onDelete ?? $restrict,
                        onUpdate: $onUpdate ?? $restrict,
                    ));
                }
            }
        }
    }

    private function checkDeprecatedUsage(string $columnName, TableDefinition $table): void
    {
        foreach ($table->getIndexes() as $index) {
            if (in_array($columnName, $index->columns, true)) {
                $this->warnings[] = "Deprecated column '{$columnName}' in table '{$table->name}' is used in an index.";
            }
        }

        foreach ($table->getRelations() as $relation) {
            if ($relation['foreignKey'] === $columnName) {
                $this->warnings[] = "Deprecated column '{$columnName}' in table '{$table->name}' is used in a relation.";
            }
        }
    }
}

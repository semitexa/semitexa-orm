<?php

declare(strict_types=1);

namespace Semitexa\Orm\Schema;

use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Aggregate;
use Semitexa\Orm\Attribute\BelongsTo;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\Deprecated;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\HasMany;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Attribute\ManyToMany;
use Semitexa\Orm\Attribute\OneToOne;
use Semitexa\Orm\Attribute\PrimaryKey;
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

        foreach ($tables as $table) {
            $tableErrors = $table->validate();
            foreach ($tableErrors as $error) {
                $this->warnings[] = $error;
            }
        }

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
        }

        $isDeprecated = !empty($deprecatedAttrs);
        $phpType = $this->resolvePhpType($property);

        // Validate PHP type vs MySqlType
        $this->validateTypeMatch($phpType, $column->type, $property->getName(), $className);

        $nullable = $column->nullable || ($property->getType() instanceof \ReflectionNamedType && $property->getType()->allowsNull());

        $colDef = new ColumnDefinition(
            name: $property->getName(),
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
        );

        if ($table->getColumn($property->getName()) !== null) {
            $this->errors[] = "Duplicate column '{$property->getName()}' in table '{$table->name}'.";
            return;
        }

        $table->addColumn($colDef);

        if ($isDeprecated) {
            $this->checkDeprecatedUsage($property->getName(), $table);
        }
    }

    private function processRelations(\ReflectionProperty $property, TableDefinition $table): void
    {
        $propName = $property->getName();

        foreach ($property->getAttributes(BelongsTo::class) as $attr) {
            /** @var BelongsTo $rel */
            $rel = $attr->newInstance();
            $table->addRelation($propName, 'belongs_to', $rel->target, $rel->foreignKey);
        }

        foreach ($property->getAttributes(HasMany::class) as $attr) {
            /** @var HasMany $rel */
            $rel = $attr->newInstance();
            $table->addRelation($propName, 'has_many', $rel->target, $rel->foreignKey);
        }

        foreach ($property->getAttributes(OneToOne::class) as $attr) {
            /** @var OneToOne $rel */
            $rel = $attr->newInstance();
            $table->addRelation($propName, 'one_to_one', $rel->target, $rel->foreignKey);
        }

        foreach ($property->getAttributes(ManyToMany::class) as $attr) {
            /** @var ManyToMany $rel */
            $rel = $attr->newInstance();
            $table->addRelation($propName, 'many_to_many', $rel->target, $rel->foreignKey, $rel->pivotTable, $rel->relatedKey);
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
            MySqlType::Varchar, MySqlType::Text => in_array($phpType, ['string', 'mixed']),
            MySqlType::Json => in_array($phpType, ['string', 'array', 'mixed']),
            MySqlType::Int, MySqlType::Bigint => in_array($phpType, ['int', 'mixed']),
            MySqlType::Decimal => in_array($phpType, ['string', 'float', 'mixed']),
            MySqlType::Boolean => in_array($phpType, ['bool', 'int', 'mixed']),
            MySqlType::Datetime, MySqlType::Timestamp, MySqlType::Date => in_array($phpType, ['DateTimeImmutable', 'DateTime', 'string', 'mixed']),
        };

        if (!$valid) {
            $this->errors[] = "Property '{$propName}' in '{$className}': PHP type '{$phpType}' is incompatible with MySQL type '{$sqlType->value}'.";
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

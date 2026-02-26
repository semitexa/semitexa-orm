<?php

declare(strict_types=1);

namespace Semitexa\Orm\Trait;

use Semitexa\Orm\Attribute\Filterable;
use Semitexa\Orm\Schema\ResourceMetadata;

/**
 * Provides filterByX($value) for main-table properties with #[Filterable],
 * and filterBy{Relation}{Column}($value) for related resource columns.
 * Criteria are returned as getFilterCriteria() and getRelationFilterCriteria() for Repository::find().
 */
trait FilterableTrait
{
    /** @var array<string, mixed> propertyName => value (main table) */
    private array $__filterCriteria = [];

    /** @var array<string, array<string, mixed>> relationProperty => [DB column name => value] on related table */
    private array $__relationFilterCriteria = [];

    /**
     * @return array<string, mixed> DB column name => value for WHERE clause on main table
     */
    public function getFilterCriteria(): array
    {
        $meta = ResourceMetadata::for(static::class);
        $propertyToColumn = $meta->getFilterableColumns();
        $result = [];
        foreach ($this->__filterCriteria as $propertyName => $value) {
            if (isset($propertyToColumn[$propertyName])) {
                $result[$propertyToColumn[$propertyName]] = $value;
            }
        }
        return $result;
    }

    /**
     * @return array<string, array<string, mixed>> relation property name => [DB column name => value]
     */
    public function getRelationFilterCriteria(): array
    {
        return $this->__relationFilterCriteria;
    }

    public function __call(string $name, array $arguments): mixed
    {
        $filterByPrefix = 'filterBy';
        if (str_starts_with($name, $filterByPrefix) && strlen($name) > strlen($filterByPrefix) && count($arguments) >= 1) {
            $suffix = substr($name, strlen($filterByPrefix));
            $propertyName = lcfirst($suffix);

            $meta = ResourceMetadata::for(static::class);

            // 1) Try main-table filter (existing behaviour)
            $ref = new \ReflectionClass(static::class);
            if ($ref->hasProperty($propertyName)) {
                $prop = $ref->getProperty($propertyName);
                if ($prop->getAttributes(Filterable::class) !== []) {
                    $this->__filterCriteria[$propertyName] = $arguments[0];
                    return $this;
                }
            }

            // 2) Try relation filter: filterBy{RelationProperty}{ColumnName} e.g. filterByUserEmail
            $relationMeta = $this->parseRelationFilterMethod($meta, $suffix);
            if ($relationMeta !== null) {
                [$relationProperty, $relatedColumnName] = $relationMeta;
                $relatedClass = $meta->getRelationByProperty($relationProperty)->targetClass;
                $relatedFilterable = ResourceMetadata::for($relatedClass)->getFilterableColumns();
                if (!isset($relatedFilterable[$relatedColumnName])) {
                    throw new \BadMethodCallException(
                        "Cannot filter by relation '{$relationProperty}.{$relatedColumnName}': "
                        . "property '{$relatedColumnName}' is not marked with #[Filterable] on {$relatedClass}."
                    );
                }
                $dbColumnName = $relatedFilterable[$relatedColumnName];
                if (!isset($this->__relationFilterCriteria[$relationProperty])) {
                    $this->__relationFilterCriteria[$relationProperty] = [];
                }
                $this->__relationFilterCriteria[$relationProperty][$dbColumnName] = $arguments[0];
                return $this;
            }

            // If we got here with filterBy* but no match, one of the above should have thrown
            throw new \BadMethodCallException(
                "Cannot filter by '{$propertyName}': property does not exist or is not marked with #[Filterable] on " . static::class . ', '
                . 'or is not a valid relation filter (relation.column) on ' . static::class . '.'
            );
        }

        throw new \BadMethodCallException('Call to undefined method ' . static::class . '::' . $name . '().');
    }

    /**
     * Parse filterBy{Relation}{Column} suffix (e.g. "UserEmail" -> ["user", "email"]).
     * Returns [relationProperty, relatedPropertyName] or null if no relation matches.
     *
     * @return array{0: string, 1: string}|null
     */
    private function parseRelationFilterMethod(ResourceMetadata $meta, string $suffix): ?array
    {
        $relations = $meta->getRelations();
        // Prefer longer relation names first so "OrderItems" matches before "Order"
        usort($relations, fn($a, $b) => strlen($b->property) <=> strlen($a->property));

        foreach ($relations as $relation) {
            $relationPascal = ucfirst($relation->property);
            if ($relationPascal === '' || strlen($suffix) <= strlen($relationPascal)) {
                continue;
            }
            if (str_starts_with($suffix, $relationPascal)) {
                $columnPart = substr($suffix, strlen($relationPascal));
                if ($columnPart === '') {
                    continue;
                }
                $columnPropertyName = lcfirst($columnPart);
                return [$relation->property, $columnPropertyName];
            }
        }
        return null;
    }
}

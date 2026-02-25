<?php

declare(strict_types=1);

namespace Semitexa\Orm\Trait;

use Semitexa\Orm\Attribute\Filterable;
use Semitexa\Orm\Schema\ResourceMetadata;

/**
 * Provides filterByX($value) methods for properties marked with #[Filterable].
 * Criteria are stored and returned as DB column name => value for Repository::find().
 */
trait FilterableTrait
{
    /** @var array<string, mixed> propertyName => value */
    private array $__filterCriteria = [];

    /**
     * @return array<string, mixed> DB column name => value for WHERE clause
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

    public function __call(string $name, array $arguments): mixed
    {
        if (str_starts_with($name, 'filterBy') && strlen($name) > 7 && count($arguments) >= 1) {
            $suffix = substr($name, 7);
            $propertyName = lcfirst($suffix);

            $ref = new \ReflectionClass(static::class);
            if (!$ref->hasProperty($propertyName)) {
                throw new \BadMethodCallException(
                    "Cannot filter by '{$propertyName}': property does not exist on " . static::class . '.'
                );
            }
            $prop = $ref->getProperty($propertyName);
            if ($prop->getAttributes(Filterable::class) === []) {
                throw new \BadMethodCallException(
                    "Cannot filter by '{$propertyName}': property is not marked with #[Filterable] on " . static::class . '.'
                );
            }

            $this->__filterCriteria[$propertyName] = $arguments[0];
            return $this;
        }

        throw new \BadMethodCallException('Call to undefined method ' . static::class . '::' . $name . '().');
    }
}

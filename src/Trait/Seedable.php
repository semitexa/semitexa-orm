<?php

declare(strict_types=1);

namespace Semitexa\Orm\Trait;

/**
 * Provides the `create()` factory method for Resource classes.
 * Used in `defaults()` to create seed data instances.
 *
 * Usage:
 *   use Seedable;
 *   public static function defaults(): array {
 *       return [
 *           self::create(id: 1, slug: 'admin', name: 'Administrator'),
 *       ];
 *   }
 */
trait Seedable
{
    protected static function newSeedInstance(): static
    {
        $ref = new \ReflectionClass(static::class);
        $constructor = $ref->getConstructor();

        if ($constructor === null || $constructor->isPublic()) {
            /** @var static $instance */
            $instance = $ref->newInstance();
            return $instance;
        }

        /** @var static $instance */
        $instance = $ref->newInstanceWithoutConstructor();
        return $instance;
    }

    /**
     * Create a new Resource instance with the given property values.
     * Named arguments must match the Resource's public properties.
     *
     * A resource model is a final readonly class whose constructor takes its
     * columns: it is built THROUGH that constructor, the values as named
     * arguments. It used to be built with none, which no valid model survives
     * (ArgumentCountError) — so seeding worked only for classes the ORM no
     * longer accepts. A class without constructor parameters still gets its
     * public properties set one by one.
     */
    public static function create(mixed ...$values): static
    {
        $ref = new \ReflectionClass(static::class);
        $constructor = $ref->getConstructor();
        if ($constructor !== null && $constructor->isPublic() && $constructor->getNumberOfParameters() > 0) {
            return static::createThroughConstructor($ref, $constructor, $values);
        }

        $instance = static::newSeedInstance();

        foreach ($values as $name => $value) {
            if (!$ref->hasProperty($name)) {
                throw new \InvalidArgumentException(
                    "Property '{$name}' does not exist on " . static::class,
                );
            }

            $prop = $ref->getProperty($name);
            if (!$prop->isPublic()) {
                throw new \InvalidArgumentException(
                    "Property '{$name}' on " . static::class . ' is not public.',
                );
            }

            $prop->setValue($instance, $value);
        }

        return $instance;
    }

    /**
     * @param \ReflectionClass<static> $ref
     * @param array<array-key, mixed> $values
     */
    private static function createThroughConstructor(\ReflectionClass $ref, \ReflectionMethod $constructor, array $values): static
    {
        $accepted = [];
        foreach ($constructor->getParameters() as $parameter) {
            $accepted[$parameter->getName()] = $parameter;
        }
        foreach (array_keys($values) as $name) {
            if (!is_string($name) || !isset($accepted[$name])) {
                throw new \InvalidArgumentException(
                    "Property '{$name}' does not exist on " . static::class . '; create() takes the constructor\'s named arguments.',
                );
            }
        }
        foreach ($accepted as $name => $parameter) {
            if (!array_key_exists($name, $values) && !$parameter->isOptional()) {
                throw new \InvalidArgumentException(
                    "Property '{$name}' is required by " . static::class . ' and was not given to create().',
                );
            }
        }

        /** @var static $instance */
        $instance = $ref->newInstanceArgs($values);

        return $instance;
    }
}

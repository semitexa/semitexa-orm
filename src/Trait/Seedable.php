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
     */
    public static function create(mixed ...$values): static
    {
        $instance = static::newSeedInstance();
        $ref = new \ReflectionClass(static::class);

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
}

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
    /**
     * Create a new Resource instance with the given property values.
     * Named arguments must match the Resource's public properties.
     */
    public static function create(mixed ...$values): static
    {
        $instance = new static();
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

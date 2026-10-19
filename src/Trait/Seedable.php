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
     * Create a new Resource instance from named arguments.
     *
     * Each name is either one of the public constructor's parameters or a
     * public, non-readonly, non-static property; the constructor parameters
     * are passed to it by name and every other value is assigned to its
     * property afterwards. A readonly model (a final readonly class whose
     * constructor takes its columns) is built entirely through that
     * constructor; a mutable class may take some values through its
     * constructor and the rest as writable properties.
     *
     * Throws InvalidArgumentException naming the argument for any name that is
     * neither (unknown, readonly, non-public or static), and naming the
     * parameter for a required constructor parameter that was left out.
     */
    public static function create(mixed ...$values): static
    {
        $ref = new \ReflectionClass(static::class);
        $constructor = $ref->getConstructor();

        $parameters = [];
        if ($constructor !== null && $constructor->isPublic()) {
            foreach ($constructor->getParameters() as $parameter) {
                $parameters[$parameter->getName()] = $parameter;
            }
        }

        $constructorArgs = [];
        $properties = [];
        foreach ($values as $name => $value) {
            if (is_string($name) && isset($parameters[$name])) {
                $constructorArgs[$name] = $value;
                continue;
            }
            $properties[] = static::writableSeedProperty($ref, $name);
        }
        foreach ($parameters as $name => $parameter) {
            if (!array_key_exists($name, $constructorArgs) && !$parameter->isOptional()) {
                throw new \InvalidArgumentException(
                    "Property '{$name}' is required by " . static::class . ' and was not given to create().',
                );
            }
        }

        if ($parameters !== []) {
            /** @var static $instance */
            $instance = $ref->newInstanceArgs($constructorArgs);
        } else {
            $instance = static::newSeedInstance();
        }

        foreach ($properties as $prop) {
            $prop->setValue($instance, $values[$prop->getName()]);
        }

        return $instance;
    }

    /** @param \ReflectionClass<static> $ref */
    private static function writableSeedProperty(\ReflectionClass $ref, int|string $name): \ReflectionProperty
    {
        if (!is_string($name) || !$ref->hasProperty($name)) {
            throw new \InvalidArgumentException(
                "Property '{$name}' does not exist on " . static::class
                . '; create() takes constructor parameters and public writable properties by name.',
            );
        }

        $prop = $ref->getProperty($name);
        if (!$prop->isPublic() || $prop->isReadOnly() || $prop->isStatic()) {
            $why = !$prop->isPublic() ? 'not public' : ($prop->isStatic() ? 'static' : 'readonly');
            throw new \InvalidArgumentException(
                "Property '{$name}' on " . static::class . " is {$why} and not a constructor parameter; create() cannot set it.",
            );
        }

        return $prop;
    }
}

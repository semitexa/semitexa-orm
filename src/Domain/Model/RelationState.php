<?php

declare(strict_types=1);

namespace Semitexa\Orm\Domain\Model;

final class RelationState
{
    private function __construct(
        private bool $loaded,
        private mixed $value,
    ) {}

    /**
     * Whether a property or parameter of this type can hold a RelationState —
     * `RelationState`, or a union with it (`array|RelationState`). Only such a
     * relation can say "not loaded" apart from "none".
     */
    public static function admittedBy(?\ReflectionType $type): bool
    {
        $types = $type instanceof \ReflectionUnionType ? $type->getTypes() : [$type];
        foreach ($types as $one) {
            if ($one instanceof \ReflectionNamedType && $one->getName() === self::class) {
                return true;
            }
        }

        return false;
    }

    public static function notLoaded(): self
    {
        return new self(
            loaded: false,
            value: null,
        );
    }

    public static function loaded(mixed $value): self
    {
        return new self(
            loaded: true,
            value: $value,
        );
    }

    public static function loadedEmptyCollection(): self
    {
        return new self(
            loaded: true,
            value: [],
        );
    }

    public static function loadedNull(): self
    {
        return new self(
            loaded: true,
            value: null,
        );
    }

    public function isLoaded(): bool
    {
        return $this->loaded;
    }

    public function isNotLoaded(): bool
    {
        return !$this->loaded;
    }

    public function value(): mixed
    {
        if (!$this->loaded) {
            throw new \LogicException('Cannot read relation value when the relation is not loaded.');
        }

        return $this->value;
    }

    public function valueOrNull(): mixed
    {
        return $this->loaded ? $this->value : null;
    }

    public function markLoaded(mixed $value): void
    {
        $this->loaded = true;
        $this->value = $value;
    }

    public function markNotLoaded(): void
    {
        $this->loaded = false;
        $this->value = null;
    }
}

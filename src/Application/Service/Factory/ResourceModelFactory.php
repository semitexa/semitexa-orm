<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service\Factory;

use Semitexa\Orm\Domain\Enum\ResourceChangeOperation;
use Semitexa\Orm\Metadata\ColumnMetadata;
use Semitexa\Orm\Metadata\ResourceModelMetadata;
use Semitexa\Orm\Metadata\ResourceModelMetadataRegistry;
use Semitexa\Orm\OrmManager;

/**
 * Valid resource models for a test, in one line:
 *
 *     $user  = ResourceModelFactory::for(UserResourceModel::class)->state(['email' => 'a@x.test'])->createOne($orm);
 *     $posts = ResourceModelFactory::for(PostResourceModel::class)->count(3)->sequence(fn (int $i) => ['position' => $i])->make();
 *
 * Each column's value comes from, in order: state(), sequence(), the column's
 * declared default, null for a nullable column (unless filled()), else the
 * ValueGenerator. A uuid key is left empty for the write to fill, an auto key
 * to the database; a manual key is generated. A soft-delete column stays null
 * and a version column starts at 0 unless a state says otherwise.
 *
 * Without seed() each call draws new values and its rows are numbered on from
 * the last call's, so two createOne() calls never collide on a unique column;
 * with seed() a call gives the same rows every run.
 */
final class ResourceModelFactory
{
    /** @var list<array<string, mixed>|\Closure(int): array<string, mixed>> */
    private array $states = [];
    /** @var (\Closure(int): array<string, mixed>)|null */
    private ?\Closure $sequence = null;
    private int $count = 1;
    private ?int $seed = null;

    /** @var array<class-string, int> rows numbered so far per model, for unseeded calls */
    private static array $rowsMade = [];
    private bool $filled = false;
    private mixed $tenant = null;

    /** @param class-string $resourceModelClass */
    private function __construct(private readonly string $resourceModelClass) {}

    /** @param class-string $resourceModelClass */
    public static function for(string $resourceModelClass): self
    {
        return new self($resourceModelClass);
    }

    /** @param array<string, mixed>|\Closure(int): array<string, mixed> $values by property name; a closure gets the row index */
    public function state(array|\Closure $values): self
    {
        $factory = clone $this;
        $factory->states[] = $values;

        return $factory;
    }

    /** @param \Closure(int): array<string, mixed> $values */
    public function sequence(\Closure $values): self
    {
        $factory = clone $this;
        $factory->sequence = $values;

        return $factory;
    }

    public function count(int $count): self
    {
        $factory = clone $this;
        $factory->count = max(0, $count);

        return $factory;
    }

    public function seed(int $seed): self
    {
        $factory = clone $this;
        $factory->seed = $seed;

        return $factory;
    }

    /** Give nullable columns a value too, instead of null. */
    public function filled(): self
    {
        $factory = clone $this;
        $factory->filled = true;

        return $factory;
    }

    /** The tenant a tenant-scoped model belongs to: set on its tenant column and passed to the write. */
    public function forTenant(mixed $tenant): self
    {
        $factory = clone $this;
        $factory->tenant = $tenant;

        return $factory;
    }

    /** @return list<object> unsaved */
    public function make(): array
    {
        $metadata = ResourceModelMetadataRegistry::default()->for($this->resourceModelClass);
        $generator = new ValueGenerator($this->seed ?? random_int(1, PHP_INT_MAX));
        $first = 0;
        if ($this->seed === null) {
            $first = self::$rowsMade[$this->resourceModelClass] ?? 0;
            self::$rowsMade[$this->resourceModelClass] = $first + $this->count;
        }
        $models = [];
        for ($i = 0; $i < $this->count; $i++) {
            $models[] = $this->build($metadata, $this->valuesFor($metadata, $generator, $i, $first + $i));
        }

        return $models;
    }

    public function makeOne(): object
    {
        return $this->count(1)->make()[0];
    }

    /** @return list<object> as written: with the keys the write filled */
    public function create(OrmManager $orm): array
    {
        $metadata = ResourceModelMetadataRegistry::default()->for($this->resourceModelClass);
        if ($this->tenant === null && $metadata->tenantColumn() !== null) {
            throw new \LogicException("{$this->resourceModelClass} is tenant-scoped: say whose rows these are with forTenant().");
        }
        $engine = $orm->getAggregateWriteEngine();

        return array_map(
            fn (object $model): object => $engine->write(ResourceChangeOperation::Insert, $model, $this->tenant),
            $this->make(),
        );
    }

    public function createOne(OrmManager $orm): object
    {
        return $this->count(1)->create($orm)[0];
    }

    /**
     * @param int $row the index within this call, for state and sequence closures
     * @param int $number the row's number across calls, for unique values
     * @return array<string, mixed>
     */
    private function valuesFor(ResourceModelMetadata $metadata, ValueGenerator $generator, int $row, int $number): array
    {
        $given = [];
        foreach ($this->states as $state) {
            $given = ($state instanceof \Closure ? $state($row) : $state) + $given;
        }
        if ($this->sequence !== null) {
            $given = $given + ($this->sequence)($row);
        }
        $tenantColumn = $metadata->tenantColumn();
        if ($tenantColumn !== null && $this->tenant !== null && !array_key_exists($tenantColumn->propertyName, $given)) {
            $given[$tenantColumn->propertyName] = $this->tenant;
        }

        $values = [];
        foreach ($metadata->columns() as $column) {
            $values[$column->propertyName] = match (true) {
                array_key_exists($column->propertyName, $given) => $given[$column->propertyName],
                // Filled, a soft-delete column hid every row from normal queries.
                $column->propertyName === $metadata->softDelete?->propertyName => null,
                $column->propertyName === $metadata->versionProperty => 0,
                default => $this->generated($column, $generator, $number),
            };
        }
        foreach ($given as $property => $value) {
            if (!array_key_exists($property, $values)) {
                throw new \InvalidArgumentException("{$this->resourceModelClass} has no column '{$property}'.");
            }
        }

        return $values;
    }

    private function generated(ColumnMetadata $column, ValueGenerator $generator, int $row): mixed
    {
        if ($column->isPrimaryKey) {
            return match ($column->primaryKeyStrategy) {
                'uuid' => '',
                'auto' => $column->nullable ? null : 0,
                // A random int collides: twenty rows on a TinyInt key share
                // one more often than not. The row number never repeats.
                default => ltrim($column->phpType, '?') === 'int' ? $this->rowKey($column, $row) : $generator->for($column, $row),
            };
        }
        if ($column->default !== null) {
            return $column->default;
        }
        if ($column->nullable && !$this->filled) {
            return null;
        }

        return $generator->for($column, $row);
    }

    /**
     * The row's number as its key, refused past what the column holds: strict
     * MySQL refuses row 128 on a TinyInt key, and clamping would give every
     * later row the same key.
     */
    private function rowKey(ColumnMetadata $column, int $row): int
    {
        $max = match ($column->type instanceof \UnitEnum ? $column->type->name : '') {
            // Boolean is a TINYINT(1) column: it holds what a TinyInt does.
            'TinyInt', 'Boolean' => 127,
            'SmallInt' => 32_767,
            'Int' => 2_147_483_647,
            default => PHP_INT_MAX,
        };
        if ($row >= $max) {
            throw new \InvalidArgumentException("{$this->resourceModelClass}::\${$column->propertyName} holds keys up to {$max}; row " . ($row + 1) . ' does not fit. Give the key in state() or make fewer rows.');
        }

        return $row + 1;
    }

    /** @param array<string, mixed> $values */
    private function build(ResourceModelMetadata $metadata, array $values): object
    {
        $class = new \ReflectionClass($metadata->className);
        $constructor = $class->getConstructor();
        $arguments = [];
        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $parameter) {
                if (array_key_exists($parameter->getName(), $values)) {
                    $arguments[$parameter->getName()] = $values[$parameter->getName()];
                    unset($values[$parameter->getName()]);
                } elseif (!$parameter->isOptional()) {
                    throw new \InvalidArgumentException("{$metadata->className}::__construct() needs '{$parameter->getName()}', which is no column.");
                }
            }
        }
        $model = $constructor !== null ? $class->newInstanceArgs($arguments) : $class->newInstance();
        foreach ($values as $property => $value) {
            $reflection = $class->getProperty($property);
            if ($reflection->isPublic() && !$reflection->isReadOnly() && !$reflection->isStatic()) {
                $reflection->setValue($model, $value);
            }
        }

        return $model;
    }
}

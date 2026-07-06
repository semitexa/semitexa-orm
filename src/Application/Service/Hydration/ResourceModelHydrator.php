<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service\Hydration;

use Semitexa\Orm\Domain\Model\RelationState;

use Semitexa\Orm\Metadata\ColumnMetadata;
use Semitexa\Orm\Metadata\ResourceModelMetadataRegistry;
use Semitexa\Orm\Domain\Model\ColumnDefinition;
use Semitexa\Orm\Domain\Model\HydrationParameter;
use Semitexa\Orm\Domain\Model\HydrationPlan;

final class ResourceModelHydrator
{
    /**
     * Per-class hydration plan cache. Reflection over a resource model's
     * constructor (ReflectionClass + getConstructor + getParameters) and the
     * per-column ColumnDefinition are constant for the lifetime of the class,
     * yet hydrate() runs once PER ROW — the hottest read path in the ORM. We
     * resolve the plan once per class per worker and reuse it for every row,
     * so an N-row result no longer pays N reflection passes.
     *
     * A value of `null` means the class has no constructor (a valid, cached
     * outcome). Keyed by class-string.
     *
     * @var array<class-string, HydrationPlan|null>
     */
    private static array $plans = [];

    /**
     * Per-class dehydration plan cache (write path) — cached ReflectionProperty
     * + ColumnDefinition per column, resolved once per class. See {@see dehydrationPlan()}.
     *
     * @var array<class-string, list<array{property: \ReflectionProperty, columnName: string, columnDef: ColumnDefinition}>>
     */
    private static array $dehydrationPlans = [];

    public function __construct(
        private readonly ?TypeCaster                    $typeCaster = null,
        private readonly ?ResourceModelMetadataRegistry $metadataRegistry = null,
    ) {}

    /**
     * @template T of object
     * @param array<string, mixed> $row
     * @param class-string<T> $resourceModelClass
     * @return T
     */
    public function hydrate(array $row, string $resourceModelClass): object
    {
        $plan = $this->plan($resourceModelClass);
        if ($plan === null) {
            return new $resourceModelClass();
        }

        $typeCaster = $this->typeCaster ?? new TypeCaster();
        $arguments = [];
        foreach ($plan->parameters as $p) {
            $arguments[] = match ($p->kind) {
                HydrationParameter::COLUMN   => $this->hydrateColumnValueFromPlan($row, $p, $typeCaster),
                HydrationParameter::RELATION_STATE => RelationState::notLoaded(),
                default                      => $p->literal, // LITERAL: relation-default / scalar default / null
            };
        }

        /** @var T */
        return $plan->reflection->newInstanceArgs($arguments);
    }

    /**
     * Resolve (and memoize) the constructor plan for a resource model class.
     * Reflection happens once per class; every subsequent row reuses it.
     *
     * @param class-string $resourceModelClass
     */
    private function plan(string $resourceModelClass): ?HydrationPlan
    {
        if (array_key_exists($resourceModelClass, self::$plans)) {
            return self::$plans[$resourceModelClass];
        }

        $metadata = ($this->metadataRegistry ?? ResourceModelMetadataRegistry::default())->for($resourceModelClass);
        $ref = new \ReflectionClass($resourceModelClass);
        $constructor = $ref->getConstructor();
        if ($constructor === null) {
            return self::$plans[$resourceModelClass] = null;
        }

        $parameters = [];
        foreach ($constructor->getParameters() as $parameter) {
            $name       = $parameter->getName();
            $allowsNull = $parameter->allowsNull();
            $hasDefault = $parameter->isDefaultValueAvailable();
            $default    = $hasDefault ? $parameter->getDefaultValue() : null;

            if ($metadata->hasColumn($name)) {
                $column = $metadata->column($name);
                $parameters[] = HydrationParameter::column(
                    name: $name,
                    column: $column,
                    columnDef: $this->toColumnDefinition($column),
                    allowsNull: $allowsNull,
                    hasDefault: $hasDefault,
                    default: $default,
                );
                continue;
            }

            if ($metadata->hasRelation($name)) {
                $type = $parameter->getType();
                if ($type instanceof \ReflectionNamedType && $type->getName() === RelationState::class) {
                    $parameters[] = HydrationParameter::relationState();
                } elseif ($hasDefault) {
                    $parameters[] = HydrationParameter::literal($default);
                } elseif ($allowsNull) {
                    $parameters[] = HydrationParameter::literal(null);
                } else {
                    throw new \InvalidArgumentException(sprintf(
                        'Relation parameter "%s" must either allow null, define a default, or use %s.',
                        $name,
                        RelationState::class,
                    ));
                }
                continue;
            }

            if ($hasDefault) {
                $parameters[] = HydrationParameter::literal($default);
                continue;
            }

            if ($allowsNull) {
                $parameters[] = HydrationParameter::literal(null);
                continue;
            }

            throw new \InvalidArgumentException(sprintf(
                'Cannot hydrate constructor parameter "%s" on %s.',
                $name,
                $resourceModelClass,
            ));
        }

        return self::$plans[$resourceModelClass] = new HydrationPlan($ref, $parameters);
    }

    private function hydrateColumnValueFromPlan(array $row, HydrationParameter $p, TypeCaster $typeCaster): mixed
    {
        $column = $p->column;
        if (!array_key_exists($column->columnName, $row)) {
            if ($p->hasDefault) {
                return $p->literal;
            }

            if ($column->nullable || $p->allowsNull) {
                return null;
            }

            throw new \InvalidArgumentException(sprintf(
                'Missing required DB column "%s" for constructor parameter "%s".',
                $column->columnName,
                $p->name,
            ));
        }

        $value = $typeCaster->castFromDb($row[$column->columnName], $p->columnDef);

        return $typeCaster->castToPropertyType(
            $value,
            $column->phpType,
            $column->nullable || $p->allowsNull,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function dehydrate(object $resourceModel): array
    {
        $typeCaster = $this->typeCaster ?? new TypeCaster();

        $data = [];
        foreach ($this->dehydrationPlan($resourceModel::class) as $entry) {
            $property = $entry['property'];
            if (!$property->isInitialized($resourceModel)) {
                continue;
            }

            $data[$entry['columnName']] = $typeCaster->castToDb(
                $property->getValue($resourceModel),
                $entry['columnDef'],
            );
        }

        return $data;
    }

    /**
     * Resolve (and memoize) the per-class dehydration plan — the write-path twin
     * of {@see plan()}. `dehydrate()` runs once per row of every save, yet it
     * built a `new \ReflectionProperty` and a `ColumnDefinition` per column on
     * every row though both are constant per class. A ReflectionProperty is
     * bound to a class, not an instance (getValue/isInitialized take the object),
     * so it is safe to cache and reuse across rows.
     *
     * @param class-string $class
     * @return list<array{property: \ReflectionProperty, columnName: string, columnDef: ColumnDefinition}>
     */
    private function dehydrationPlan(string $class): array
    {
        if (isset(self::$dehydrationPlans[$class])) {
            return self::$dehydrationPlans[$class];
        }

        $metadata = ($this->metadataRegistry ?? ResourceModelMetadataRegistry::default())->for($class);
        $plan = [];
        foreach ($metadata->columns() as $column) {
            $plan[] = [
                'property'   => new \ReflectionProperty($class, $column->propertyName),
                'columnName' => $column->columnName,
                'columnDef'  => $this->toColumnDefinition($column),
            ];
        }

        return self::$dehydrationPlans[$class] = $plan;
    }

    private function toColumnDefinition(ColumnMetadata $column): ColumnDefinition
    {
        return new ColumnDefinition(
            name: $column->columnName,
            type: $column->type,
            phpType: $column->phpType,
            nullable: $column->nullable,
            length: $column->length,
            precision: $column->precision,
            scale: $column->scale,
            default: $column->default,
            isPrimaryKey: $column->isPrimaryKey,
            pkStrategy: $column->primaryKeyStrategy ?? 'auto',
            propertyName: $column->propertyName,
        );
    }
}

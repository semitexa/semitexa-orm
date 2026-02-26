<?php

declare(strict_types=1);

namespace Semitexa\Orm\Schema;

use Semitexa\Orm\Attribute\BelongsTo;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\Filterable;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\HasMany;
use Semitexa\Orm\Attribute\ManyToMany;
use Semitexa\Orm\Attribute\OneToOne;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Hydration\RelationMeta;
use Semitexa\Orm\Hydration\RelationType;

/**
 * Cached reflection metadata for a Resource class.
 *
 * Resolving table name and PK column via Reflection on every query caused
 * identical code to be duplicated across CascadeSaver, RelationLoader,
 * SmartUpsert, and SelectQuery. This class centralises that logic behind a
 * static per-class cache so that Reflection happens exactly once per worker
 * lifetime regardless of how many queries are executed.
 */
final class ResourceMetadata
{
    /** @var array<class-string, self> */
    private static array $cache = [];

    private string $tableName;
    private string $pkColumn;
    private string $pkPropertyName;

    /** @var array<string, string> propertyName => columnName for #[Filterable] properties */
    private array $filterableColumns = [];

    /** @var RelationMeta[] */
    private array $relations = [];

    private function __construct(string $resourceClass)
    {
        $ref = new \ReflectionClass($resourceClass);

        // Resolve table name
        $fromTableAttrs = $ref->getAttributes(FromTable::class);
        if ($fromTableAttrs === []) {
            throw new \RuntimeException("Class {$resourceClass} has no #[FromTable] attribute.");
        }
        /** @var FromTable $ft */
        $ft = $fromTableAttrs[0]->newInstance();
        $this->tableName = $ft->name;

        // Resolve PK: track both the DB column name and the PHP property name separately.
        // They differ when #[Column(name: 'user_id')] renames a property (e.g. $id → user_id).
        $this->pkColumn = 'id';
        $this->pkPropertyName = 'id';
        foreach ($ref->getProperties() as $prop) {
            if ($prop->getAttributes(PrimaryKey::class) !== []) {
                $this->pkPropertyName = $prop->getName();
                $colAttrs = $prop->getAttributes(Column::class);
                if ($colAttrs !== []) {
                    /** @var Column $col */
                    $col = $colAttrs[0]->newInstance();
                    $this->pkColumn = $col->name ?? $prop->getName();
                } else {
                    $this->pkColumn = $prop->getName();
                }
                break;
            }
        }

        // Resolve filterable property => column name map
        foreach ($ref->getProperties() as $prop) {
            $filterableAttrs = $prop->getAttributes(Filterable::class);
            if ($filterableAttrs === []) {
                continue;
            }
            $propName = $prop->getName();
            $colAttrs = $prop->getAttributes(Column::class);
            $columnName = $propName;
            if ($colAttrs !== []) {
                /** @var Column $col */
                $col = $colAttrs[0]->newInstance();
                $columnName = $col->name ?? $propName;
            }
            /** @var Filterable $fa */
            $fa = $filterableAttrs[0]->newInstance();
            if ($fa->name !== null) {
                $columnName = $fa->name;
            }
            $this->filterableColumns[$propName] = $columnName;
        }

        // Resolve relations (BelongsTo, HasMany, OneToOne, ManyToMany)
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            foreach ($prop->getAttributes(BelongsTo::class) as $attr) {
                /** @var BelongsTo $bt */
                $bt = $attr->newInstance();
                $this->relations[] = new RelationMeta(
                    property: $prop->getName(),
                    type: RelationType::BelongsTo,
                    targetClass: $bt->target,
                    foreignKey: $bt->foreignKey,
                );
            }
            foreach ($prop->getAttributes(HasMany::class) as $attr) {
                /** @var HasMany $hm */
                $hm = $attr->newInstance();
                $this->relations[] = new RelationMeta(
                    property: $prop->getName(),
                    type: RelationType::HasMany,
                    targetClass: $hm->target,
                    foreignKey: $hm->foreignKey,
                );
            }
            foreach ($prop->getAttributes(OneToOne::class) as $attr) {
                /** @var OneToOne $oo */
                $oo = $attr->newInstance();
                $this->relations[] = new RelationMeta(
                    property: $prop->getName(),
                    type: RelationType::OneToOne,
                    targetClass: $oo->target,
                    foreignKey: $oo->foreignKey,
                );
            }
            foreach ($prop->getAttributes(ManyToMany::class) as $attr) {
                /** @var ManyToMany $mm */
                $mm = $attr->newInstance();
                $this->relations[] = new RelationMeta(
                    property: $prop->getName(),
                    type: RelationType::ManyToMany,
                    targetClass: $mm->target,
                    foreignKey: $mm->foreignKey,
                    pivotTable: $mm->pivotTable,
                    relatedKey: $mm->relatedKey,
                );
            }
        }
    }

    /**
     * Return cached metadata for the given Resource class.
     *
     * @param class-string $resourceClass
     */
    public static function for(string $resourceClass): self
    {
        if (!isset(self::$cache[$resourceClass])) {
            self::$cache[$resourceClass] = new self($resourceClass);
        }

        return self::$cache[$resourceClass];
    }

    public function getTableName(): string
    {
        return $this->tableName;
    }

    public function getPkColumn(): string
    {
        return $this->pkColumn;
    }

    /**
     * Read the PK value from a Resource instance using the PHP property name,
     * not the DB column name (they differ when #[Column(name: '...')] is used).
     * Returns null if the property is uninitialized or absent.
     */
    public function getPkValue(object $resource): mixed
    {
        $ref = new \ReflectionClass($resource);

        if ($ref->hasProperty($this->pkPropertyName)) {
            $prop = $ref->getProperty($this->pkPropertyName);
            return $prop->isInitialized($resource) ? $prop->getValue($resource) : null;
        }

        return null;
    }

    /**
     * PHP property name of the primary key (may differ from DB column name).
     */
    public function getPkPropertyName(): string
    {
        return $this->pkPropertyName;
    }

    /**
     * Property names to DB column names for all #[Filterable] properties.
     *
     * @return array<string, string> propertyName => columnName
     */
    public function getFilterableColumns(): array
    {
        return $this->filterableColumns;
    }

    /**
     * Relation metadata for filtering and eager loading.
     *
     * @return RelationMeta[]
     */
    public function getRelations(): array
    {
        return $this->relations;
    }

    /**
     * Get relation meta by property name, or null if not a relation.
     */
    public function getRelationByProperty(string $propertyName): ?RelationMeta
    {
        foreach ($this->relations as $meta) {
            if ($meta->property === $propertyName) {
                return $meta;
            }
        }
        return null;
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Orm\Schema;

use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\Filterable;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;

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

    /** @var array<string, string> propertyName => columnName for #[Filterable] properties */
    private array $filterableColumns = [];

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

        // Resolve PK column (DB name — may differ from property name via #[Column(name: ...)])
        $this->pkColumn = 'id';
        foreach ($ref->getProperties() as $prop) {
            if ($prop->getAttributes(PrimaryKey::class) !== []) {
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
     * Read the PK value from a Resource instance.
     * Returns null if the property is uninitialized or absent.
     */
    public function getPkValue(object $resource): mixed
    {
        $ref = new \ReflectionClass($resource);

        // Try the resolved PK property first
        if ($ref->hasProperty($this->pkColumn)) {
            $prop = $ref->getProperty($this->pkColumn);
            return $prop->isInitialized($resource) ? $prop->getValue($resource) : null;
        }

        return null;
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
}

<?php

declare(strict_types=1);

namespace Semitexa\Orm\Repository;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Attribute\BelongsTo;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\HasMany;
use Semitexa\Orm\Attribute\ManyToMany;
use Semitexa\Orm\Attribute\OneToOne;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Hydration\Hydrator;
use Semitexa\Orm\Query\DeleteQuery;
use Semitexa\Orm\Query\InsertQuery;
use Semitexa\Orm\Query\UpdateQuery;

class CascadeSaver
{
    public function __construct(
        private readonly DatabaseAdapterInterface $adapter,
        private readonly Hydrator $hydrator,
    ) {}

    /**
     * Persist "touched" relation fields on a Resource after the main save.
     *
     * @param object $resource The saved Resource (with PK already set)
     */
    public function saveTouchedRelations(object $resource): void
    {
        $ref = new \ReflectionClass($resource);
        $parentPk = $this->resolvePkValue($resource);

        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if (!$prop->isInitialized($resource)) {
                continue;
            }

            // BelongsTo — related object is the "parent", we don't cascade to parent
            // (FK on our side is already in the main save)

            // HasMany — save each child, set FK to parent PK
            foreach ($prop->getAttributes(HasMany::class) as $attr) {
                /** @var HasMany $hm */
                $hm = $attr->newInstance();
                $children = $prop->getValue($resource);
                if (is_array($children)) {
                    $this->saveHasMany($children, $hm, $parentPk);
                }
            }

            // OneToOne — save the related record, set FK to parent PK
            foreach ($prop->getAttributes(OneToOne::class) as $attr) {
                /** @var OneToOne $oo */
                $oo = $attr->newInstance();
                $related = $prop->getValue($resource);
                if (is_object($related)) {
                    $this->saveOneToOne($related, $oo, $parentPk);
                }
            }

            // ManyToMany — sync pivot table
            foreach ($prop->getAttributes(ManyToMany::class) as $attr) {
                /** @var ManyToMany $mm */
                $mm = $attr->newInstance();
                $related = $prop->getValue($resource);
                if (is_array($related)) {
                    $this->saveManyToMany($related, $mm, $parentPk);
                }
            }
        }
    }

    /**
     * @param object[] $children
     */
    private function saveHasMany(array $children, HasMany $hm, mixed $parentPk): void
    {
        $targetTable = $this->resolveTableName($hm->target);
        $targetPkCol = $this->resolvePkColumn($hm->target);

        foreach ($children as $child) {
            // Set FK on child
            $fkProp = new \ReflectionProperty($child, $hm->foreignKey);
            $fkProp->setValue($child, $parentPk);

            $this->saveResource($child, $targetTable, $targetPkCol);
        }
    }

    private function saveOneToOne(object $related, OneToOne $oo, mixed $parentPk): void
    {
        $targetTable = $this->resolveTableName($oo->target);
        $targetPkCol = $this->resolvePkColumn($oo->target);

        // Set FK on related
        $fkProp = new \ReflectionProperty($related, $oo->foreignKey);
        $fkProp->setValue($related, $parentPk);

        $this->saveResource($related, $targetTable, $targetPkCol);
    }

    /**
     * @param object[] $relatedItems
     */
    private function saveManyToMany(array $relatedItems, ManyToMany $mm, mixed $parentPk): void
    {
        $targetPkCol = $this->resolvePkColumn($mm->target);

        // Delete existing pivot rows for this parent
        $this->adapter->execute(
            "DELETE FROM `{$mm->pivotTable}` WHERE `{$mm->foreignKey}` = :parent_pk",
            ['parent_pk' => $parentPk],
        );

        // Insert new pivot rows
        foreach ($relatedItems as $related) {
            $relatedPk = $this->resolvePkValue($related);
            if ($relatedPk === null) {
                continue;
            }

            $this->adapter->execute(
                "INSERT INTO `{$mm->pivotTable}` (`{$mm->foreignKey}`, `{$mm->relatedKey}`) VALUES (:parent_pk, :related_pk)",
                ['parent_pk' => $parentPk, 'related_pk' => $relatedPk],
            );
        }
    }

    private function saveResource(object $resource, string $table, string $pkColumn): void
    {
        $data = $this->hydrator->dehydrate($resource);
        $pkValue = $data[$pkColumn] ?? null;

        if ($pkValue === null || $pkValue === 0 || $pkValue === '') {
            unset($data[$pkColumn]);
            $insertId = (new InsertQuery($table, $this->adapter))->execute($data);

            if ($insertId !== '' && $insertId !== '0') {
                $ref = new \ReflectionProperty($resource, $pkColumn);
                $type = $ref->getType();
                $pkTyped = ($type instanceof \ReflectionNamedType && $type->getName() === 'int')
                    ? (int) $insertId
                    : $insertId;
                $ref->setValue($resource, $pkTyped);
            }
        } else {
            (new UpdateQuery($table, $this->adapter))->execute($data, $pkColumn);
        }
    }

    private function resolvePkValue(object $resource): mixed
    {
        $ref = new \ReflectionClass($resource);
        foreach ($ref->getProperties() as $prop) {
            if ($prop->getAttributes(PrimaryKey::class) !== []) {
                return $prop->isInitialized($resource) ? $prop->getValue($resource) : null;
            }
        }
        // Fallback to 'id'
        if ($ref->hasProperty('id')) {
            $prop = $ref->getProperty('id');
            return $prop->isInitialized($resource) ? $prop->getValue($resource) : null;
        }
        return null;
    }

    private function resolveTableName(string $resourceClass): string
    {
        $ref = new \ReflectionClass($resourceClass);
        $attrs = $ref->getAttributes(FromTable::class);
        if ($attrs === []) {
            throw new \RuntimeException("Class {$resourceClass} has no #[FromTable] attribute.");
        }
        /** @var FromTable $ft */
        $ft = $attrs[0]->newInstance();
        return $ft->name;
    }

    private function resolvePkColumn(string $resourceClass): string
    {
        $ref = new \ReflectionClass($resourceClass);
        foreach ($ref->getProperties() as $prop) {
            if ($prop->getAttributes(PrimaryKey::class) !== []) {
                return $prop->getName();
            }
        }
        return 'id';
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Orm\Repository;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Attribute\HasMany;
use Semitexa\Orm\Attribute\ManyToMany;
use Semitexa\Orm\Attribute\OneToOne;
use Semitexa\Orm\Schema\ResourceMetadata;

class CascadeDeleter
{
    public function __construct(
        private readonly DatabaseAdapterInterface $adapter,
    ) {}

    /**
     * Delete child records linked to $resource before (or after) the main DELETE.
     *
     * - HasMany  → DELETE child rows WHERE foreign_key = parent_pk
     * - OneToOne → DELETE related row WHERE foreign_key = parent_pk
     * - ManyToMany → DELETE pivot rows WHERE foreign_key = parent_pk
     *   (the related records themselves are NOT deleted — they may belong to others)
     * - BelongsTo → skipped (parent is not owned by this record)
     */
    public function deleteRelations(object $resource): void
    {
        $ref      = new \ReflectionClass($resource);
        $parentPk = ResourceMetadata::for($resource::class)->getPkValue($resource);

        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            foreach ($prop->getAttributes(HasMany::class) as $attr) {
                /** @var HasMany $hm */
                $hm = $attr->newInstance();
                $this->deleteByFk(
                    ResourceMetadata::for($hm->target)->getTableName(),
                    $hm->foreignKey,
                    $parentPk,
                );
            }

            foreach ($prop->getAttributes(OneToOne::class) as $attr) {
                /** @var OneToOne $oo */
                $oo = $attr->newInstance();
                $this->deleteByFk(
                    ResourceMetadata::for($oo->target)->getTableName(),
                    $oo->foreignKey,
                    $parentPk,
                );
            }

            foreach ($prop->getAttributes(ManyToMany::class) as $attr) {
                /** @var ManyToMany $mm */
                $mm = $attr->newInstance();
                $this->deleteByFk($mm->pivotTable, $mm->foreignKey, $parentPk);
            }
        }
    }

    private function deleteByFk(string $table, string $fkColumn, mixed $parentPk): void
    {
        $this->adapter->execute(
            "DELETE FROM `{$table}` WHERE `{$fkColumn}` = :parent_pk",
            ['parent_pk' => $parentPk],
        );
    }
}

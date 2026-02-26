<?php

declare(strict_types=1);

namespace Semitexa\Orm\Trait;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;

trait SoftDeletes
{
    #[Column(type: MySqlType::Datetime, nullable: true)]
    public ?\DateTimeImmutable $deleted_at = null;

    /**
     * Mark the resource as soft-deleted (sets deleted_at to now).
     * Called automatically by AbstractRepository::delete() when this trait is present.
     */
    public function markAsDeleted(): void
    {
        $this->deleted_at = new \DateTimeImmutable();
    }

    /**
     * Restore a soft-deleted resource (clears deleted_at).
     */
    public function restore(): void
    {
        $this->deleted_at = null;
    }

    /**
     * Whether this resource is currently soft-deleted.
     */
    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }
}

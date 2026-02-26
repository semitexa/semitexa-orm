<?php

declare(strict_types=1);

namespace Semitexa\Orm\Trait;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;

trait HasTimestamps
{
    #[Column(type: MySqlType::Datetime)]
    public ?\DateTimeImmutable $created_at = null;

    #[Column(type: MySqlType::Datetime)]
    public ?\DateTimeImmutable $updated_at = null;

    /**
     * Call from AbstractRepository::beforeSave() or override beforeSave() in your repository.
     * Sets created_at on first insert (null → now) and always refreshes updated_at.
     */
    public function touchTimestamps(): void
    {
        $now = new \DateTimeImmutable();
        if ($this->created_at === null) {
            $this->created_at = $now;
        }
        $this->updated_at = $now;
    }
}

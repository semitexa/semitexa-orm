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
}

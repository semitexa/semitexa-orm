<?php

declare(strict_types=1);

namespace Semitexa\Orm\Trait;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;

trait SoftDeletes
{
    #[Column(type: MySqlType::Datetime, nullable: true)]
    public ?\DateTimeImmutable $deleted_at = null;
}

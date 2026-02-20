<?php

declare(strict_types=1);

namespace Semitexa\Orm\Trait;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;

trait HasUuid
{
    #[Column(type: MySqlType::Varchar, length: 36)]
    public string $uuid = '';
}

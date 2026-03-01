<?php

declare(strict_types=1);

namespace Semitexa\Orm\Trait;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Uuid\Uuid7;

trait HasUuidV7
{
    #[PrimaryKey(strategy: 'uuid')]
    #[Column(type: MySqlType::Binary, length: 16)]
    public string $id = '';

    public function ensureUuid(): void
    {
        if ($this->id === '') {
            $this->id = Uuid7::generate();
        }
    }
}

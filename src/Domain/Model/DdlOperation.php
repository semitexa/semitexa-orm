<?php

declare(strict_types=1);

namespace Semitexa\Orm\Domain\Model;

use Semitexa\Orm\Domain\Enum\DdlOperationType;


readonly class DdlOperation
{
    public function __construct(
        public string $sql,
        public DdlOperationType $type,
        public string $tableName,
        public bool $isDestructive,
        public string $description,
    ) {}
}

<?php

declare(strict_types=1);

namespace Semitexa\Orm\Contract;

interface TableModelMapper
{
    public function toDomain(object $tableModel): object;

    public function toTableModel(object $domainModel): object;
}

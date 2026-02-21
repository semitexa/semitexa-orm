<?php

declare(strict_types=1);

namespace Semitexa\Orm\Query;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;

class DeleteQuery
{
    public function __construct(
        private readonly string $table,
        private readonly DatabaseAdapterInterface $adapter,
    ) {}

    /**
     * Delete a row by primary key value.
     */
    public function execute(string $pkColumn, mixed $pkValue): void
    {
        $sql = "DELETE FROM `{$this->table}` WHERE `{$pkColumn}` = :pk_value";
        $this->adapter->execute($sql, ['pk_value' => $pkValue]);
    }
}

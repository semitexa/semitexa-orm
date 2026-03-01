<?php

declare(strict_types=1);

namespace Semitexa\Orm\Event;

use Semitexa\Core\Attributes\AsEvent;

#[AsEvent]
class ResourceBroadcastEvent
{
    private string $resourceClass;
    private string $tableName;
    private string $pkColumn;
    private int|string $pkValue;
    private string $operation;
    /** @var array<string, mixed> */
    private array $broadcastData;

    public function getResourceClass(): string
    {
        return $this->resourceClass;
    }

    public function setResourceClass(string $resourceClass): void
    {
        $this->resourceClass = $resourceClass;
    }

    public function getTableName(): string
    {
        return $this->tableName;
    }

    public function setTableName(string $tableName): void
    {
        $this->tableName = $tableName;
    }

    public function getPkColumn(): string
    {
        return $this->pkColumn;
    }

    public function setPkColumn(string $pkColumn): void
    {
        $this->pkColumn = $pkColumn;
    }

    public function getPkValue(): int|string
    {
        return $this->pkValue;
    }

    public function setPkValue(int|string $pkValue): void
    {
        $this->pkValue = $pkValue;
    }

    public function getOperation(): string
    {
        return $this->operation;
    }

    public function setOperation(string $operation): void
    {
        $this->operation = $operation;
    }

    /** @return array<string, mixed> */
    public function getBroadcastData(): array
    {
        return $this->broadcastData;
    }

    /** @param array<string, mixed> $broadcastData */
    public function setBroadcastData(array $broadcastData): void
    {
        $this->broadcastData = $broadcastData;
    }
}

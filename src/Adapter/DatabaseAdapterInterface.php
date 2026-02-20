<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

interface DatabaseAdapterInterface
{
    public function supports(ServerCapability $capability): bool;

    public function getServerVersion(): string;

    public function execute(string $sql, array $params = []): \PDOStatement;

    public function query(string $sql): \PDOStatement;

    public function lastInsertId(): string;
}

<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

interface DatabaseAdapterInterface
{
    public function supports(ServerCapability $capability): bool;

    public function getServerVersion(): string;

    /**
     * Execute a prepared SQL statement.
     *
     * Returns a QueryResult containing all fetched rows, rowCount, and lastInsertId.
     * All data is materialized before the connection returns to the pool,
     * ensuring coroutine safety in Swoole environments.
     *
     * @param array<string, mixed> $params
     */
    public function execute(string $sql, array $params = []): QueryResult;

    /**
     * Execute a raw SQL query (no parameters).
     */
    public function query(string $sql): QueryResult;

    /**
     * Get the last auto-generated ID from the most recent INSERT on this adapter.
     *
     * @deprecated Use QueryResult::$lastInsertId from execute() instead.
     *             Kept for backward compatibility during transition.
     */
    public function lastInsertId(): string;
}

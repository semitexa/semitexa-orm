<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Collection;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\QueryResult;
use Semitexa\Orm\Adapter\ServerCapability;

/**
 * One Way Phase 2 fixture: answers `SELECT COUNT(*)` with a fixed
 * total and any other SELECT with fixed rows, recording every
 * executed (sql, params) pair — the SQL-capture seam the collection
 * compiler tests assert against.
 */
final class CollectionFakeAdapter implements DatabaseAdapterInterface
{
    /** @var list<array{sql: string, params: array<string|int, mixed>}> */
    public array $executed = [];

    /** @param list<array<string, mixed>> $rows */
    public function __construct(
        private readonly int $total = 0,
        private readonly array $rows = [],
    ) {
    }

    public function supports(ServerCapability $capability): bool
    {
        return false;
    }

    public function getServerVersion(): string
    {
        return 'fake';
    }

    public function execute(string $sql, array $params = []): QueryResult
    {
        $this->executed[] = ['sql' => $sql, 'params' => $params];

        if (str_starts_with($sql, 'SELECT COUNT(*)')) {
            return new QueryResult(rows: [['__c' => $this->total]], rowCount: 1, lastInsertId: '0');
        }

        return new QueryResult(rows: $this->rows, rowCount: count($this->rows), lastInsertId: '0');
    }

    public function query(string $sql): QueryResult
    {
        return $this->execute($sql);
    }

    public function lastInsertId(): string
    {
        return '0';
    }
}

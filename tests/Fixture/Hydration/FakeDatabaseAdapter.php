<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Hydration;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\QueryResult;
use Semitexa\Orm\Adapter\ServerCapability;

final class FakeDatabaseAdapter implements DatabaseAdapterInterface
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $responsesBySql;

    /** @var list<array{sql: string, params: array<string|int, mixed>}> */
    public array $executed = [];

    /**
     * @param array<string, array<int, array<string, mixed>>> $responsesBySql
     */
    public function __construct(array $responsesBySql)
    {
        $this->responsesBySql = $responsesBySql;
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

        return new QueryResult(
            rows: $this->responsesBySql[$sql] ?? [],
            rowCount: 0,
            lastInsertId: '0',
        );
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

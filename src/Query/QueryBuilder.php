<?php

declare(strict_types=1);

namespace Semitexa\Orm\Query;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Hydration\StreamingHydrator;

class QueryBuilder
{
    public function __construct(
        private readonly DatabaseAdapterInterface $adapter,
        private readonly StreamingHydrator $hydrator,
    ) {}

    public function select(string $table, string $resourceClass): SelectQuery
    {
        return new SelectQuery($table, $resourceClass, $this->adapter, $this->hydrator);
    }

    public function insert(string $table): InsertQuery
    {
        return new InsertQuery($table, $this->adapter);
    }

    public function update(string $table): UpdateQuery
    {
        return new UpdateQuery($table, $this->adapter);
    }

    public function delete(string $table): DeleteQuery
    {
        return new DeleteQuery($table, $this->adapter);
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Orm\Exception;

/**
 * MySQL 3024: the statement exceeded max_execution_time (the ORM's
 * DB_QUERY_TIMEOUT ceiling) and was killed server-side. The connection
 * itself is healthy. Transient in principle — the same query may finish
 * under less load — but never auto-retried: replaying a query that just
 * proved too expensive doubles the pressure that killed it.
 */
final class QueryTimeoutException extends DatabaseException
{
    public function isTransient(): bool
    {
        return true;
    }
}

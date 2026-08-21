<?php

declare(strict_types=1);

namespace Semitexa\Orm\Exception;

/**
 * The server connection died (MySQL 2006 "server has gone away", 2013 "lost
 * connection during query", SQLSTATE 08xxx). Transient: the same statement may
 * succeed on a FRESH connection — but only when no transaction was open and
 * the statement is read-only or idempotent; a write may have been applied
 * before the connection dropped.
 */
final class ConnectionLostException extends DatabaseException
{
    public function isTransient(): bool
    {
        return true;
    }
}

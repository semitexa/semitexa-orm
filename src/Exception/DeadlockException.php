<?php

declare(strict_types=1);

namespace Semitexa\Orm\Exception;

/**
 * MySQL 1213 / SQLSTATE 40001: this transaction was chosen as the deadlock
 * victim and has ALREADY been rolled back by the server. Transient — but the
 * retry unit is the WHOLE transaction (see TransactionManager::runWithRetry),
 * never the single statement that surfaced the error.
 */
final class DeadlockException extends DatabaseException
{
    public function isTransient(): bool
    {
        return true;
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Orm\Exception;

/**
 * MySQL 1205: waited longer than innodb_lock_wait_timeout for a row lock.
 * Only the STATEMENT was rolled back (with the default
 * innodb_rollback_on_timeout=OFF the transaction itself is still open), but
 * the ORM treats the whole transaction as the retry unit anyway — partial
 * replays of a half-applied transaction are how data diverges.
 */
final class LockWaitTimeoutException extends DatabaseException
{
    public function isTransient(): bool
    {
        return true;
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Orm\Exception;

/**
 * Base class for classified driver/pool failures.
 *
 * Raw \PDOException gives callers no safe way to decide "retry or fail":
 * the SQLSTATE/errno live in an untyped errorInfo array, and retrying the
 * wrong class of error (a constraint violation, a mid-transaction failure)
 * turns one bug into two. The subclasses carry that decision in the type:
 * {@see isTransient()} is true exactly when the same work MAY succeed on a
 * clean retry — a fresh connection for {@see ConnectionLostException}, a
 * whole-transaction replay for {@see DeadlockException} /
 * {@see LockWaitTimeoutException} (MySQL has already rolled the transaction
 * back; retrying a single statement of it is never correct).
 */
class DatabaseException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $sqlState = null,
        public readonly ?int $driverCode = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** Whether the same work may succeed if retried on clean state. */
    public function isTransient(): bool
    {
        return false;
    }
}

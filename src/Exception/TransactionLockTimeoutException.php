<?php

declare(strict_types=1);

namespace Semitexa\Orm\Exception;

/**
 * Waited too long for the intra-worker transaction lock that serialises outer
 * transactions on a single shared connection (the SQLite path — see
 * {@see \Semitexa\Orm\Application\Service\Transaction\TransactionManager}).
 *
 * Distinct from {@see LockWaitTimeoutException}, which is MySQL 1205: no
 * server was involved here and nothing was rolled back, because this
 * transaction never opened. The wait is bounded rather than indefinite for the
 * reason the connection pool bounds its own — a coroutine parked forever is a
 * request that is never answered, with nothing in the logs to say why.
 *
 * Transient by type: the lock holder does finish. It is deliberately NOT in
 * {@see \Semitexa\Orm\Application\Service\Transaction\TransactionManager::runWithRetry()}'s
 * catch list — after a full timeout the realistic causes are a transaction
 * opened from a coroutine spawned INSIDE another transaction (which can never
 * clear, so retrying only doubles the hang) or contention that a second wait
 * will not fix.
 */
final class TransactionLockTimeoutException extends DatabaseException
{
    public function isTransient(): bool
    {
        return true;
    }
}

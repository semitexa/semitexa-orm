<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service\Transaction;

/**
 * Work TransactionManager::afterRollback() runs if the transaction (or its
 * savepoint) rolls back; with $evenIfCommitFailed also
 * when the COMMIT itself threw.
 */
final readonly class AfterRollback
{
    public function __construct(public \Closure $work, public bool $evenIfCommitFailed = false) {}
}

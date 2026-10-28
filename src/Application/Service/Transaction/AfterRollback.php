<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service\Transaction;

/** Work TransactionManager::afterRollback() runs if the transaction (or its savepoint) rolls back. */
final readonly class AfterRollback
{
    public function __construct(public \Closure $work) {}
}

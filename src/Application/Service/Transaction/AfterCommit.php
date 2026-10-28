<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service\Transaction;

/** Work TransactionManager::afterCommit() holds back until the outermost commit. */
final readonly class AfterCommit
{
    public function __construct(public \Closure $work) {}
}

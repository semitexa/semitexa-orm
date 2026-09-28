<?php

declare(strict_types=1);

namespace Semitexa\Orm\Exception;

/**
 * The session holding the schema-sync lock ended before the sync did, so the
 * lock was released mid-cycle and another sync may have run alongside this
 * one. Re-run the sync: diffing again against what is now there is safe.
 */
final class SchemaSyncLockLostException extends \RuntimeException
{
    public static function for(string $lockName, ?\Throwable $previous = null): self
    {
        return new self(
            "The schema-sync lock \"{$lockName}\" was lost during the sync (its database session ended); "
            . 'another sync may have run at the same time. Re-run orm:sync to converge.',
            0,
            $previous,
        );
    }
}

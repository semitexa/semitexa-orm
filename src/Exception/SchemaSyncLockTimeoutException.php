<?php

declare(strict_types=1);

namespace Semitexa\Orm\Exception;

final class SchemaSyncLockTimeoutException extends \RuntimeException
{
    public static function for(string $lockName, int $waitSeconds): self
    {
        return new self(sprintf(
            'Another schema sync held the lock "%s" for more than %d seconds; nothing was changed. '
            . 'Retry once it finishes (SELECT IS_USED_LOCK(\'%s\') names the holding connection).',
            $lockName,
            $waitSeconds,
            $lockName,
        ));
    }
}

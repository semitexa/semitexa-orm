<?php

declare(strict_types=1);

namespace Semitexa\Orm\Exception;

/**
 * A statement tried to write a #[Replicated] table without going through the
 * ORM write engine: the change would stay on this node and never replicate.
 */
final class ReplicatedTableWriteException extends \LogicException
{
    public static function for(string $table, string $sql): self
    {
        return new self(sprintf(
            'Table "%s" is #[Replicated]: write it through the ORM repository / write engine, which captures the change '
            . 'for the other nodes. A raw statement or query builder would change it here only. Refused: %s',
            $table,
            mb_substr(preg_replace('/\s+/', ' ', trim($sql)) ?? $sql, 0, 160),
        ));
    }
}

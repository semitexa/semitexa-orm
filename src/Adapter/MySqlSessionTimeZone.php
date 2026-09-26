<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

/**
 * Pins every MySQL session to UTC, the zone TypeCaster writes and reads
 * datetimes in.
 *
 * A TIMESTAMP column converts from the SESSION zone to UTC on write and back
 * on read. With a server or session zone of, say, +02:00 a value bound as
 * 04:00 (UTC) is stored as 02:00 UTC: the writing session reads its own
 * mistake back unchanged, every other client sees the wrong instant, and
 * NOW() / CURRENT_TIMESTAMP defaults disagree with PHP by the offset. In a
 * zone with daylight saving a UTC wall clock can even fall into the skipped
 * hour. `time_zone` is the same variable on MySQL and MariaDB and an offset
 * needs no zone tables, so this cannot fail on a flavor the way a
 * flavor-specific init command would — which is why a failure here is a
 * connect failure rather than a logged warning.
 */
final class MySqlSessionTimeZone
{
    public const STATEMENT = "SET time_zone = '+00:00'";

    public static function applyTo(\PDO $pdo): \PDO
    {
        $pdo->exec(self::STATEMENT);

        return $pdo;
    }
}

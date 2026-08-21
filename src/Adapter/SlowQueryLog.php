<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

use Semitexa\Core\Environment;
use Semitexa\Core\Log\StaticLoggerBridge;

/**
 * Opt-in slow-query logging: set DB_SLOW_QUERY_MS to a positive number of
 * milliseconds and every statement at or above the threshold is logged with
 * its duration. Off by default (threshold 0) — the adapters then skip even
 * the timing, so production pays nothing until the operator turns it on.
 *
 * The threshold is read once per process: it is boot configuration, and
 * re-reading the environment per query would cost more than the timing.
 */
final class SlowQueryLog
{
    private static ?float $thresholdMs = null;

    public static function thresholdMs(): float
    {
        return self::$thresholdMs ??= (float) (Environment::getEnvValue('DB_SLOW_QUERY_MS', '0') ?? '0');
    }

    public static function maybeLog(string $sql, float $milliseconds): void
    {
        if ($milliseconds < self::thresholdMs()) {
            return;
        }

        StaticLoggerBridge::warning('orm', 'Slow query.', [
            'ms' => round($milliseconds, 1),
            'sql' => \strlen($sql) > 500 ? substr($sql, 0, 500) . '…' : $sql,
        ]);
    }

    /** Test seam: reset the memoized threshold so a test can vary the env. */
    public static function resetForTests(): void
    {
        self::$thresholdMs = null;
    }
}

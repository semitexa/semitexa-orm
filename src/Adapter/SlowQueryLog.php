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
        $threshold = self::thresholdMs();

        // Disabled is disabled: with the threshold at 0 no duration is < 0,
        // so a bare comparison would log EVERY query. The adapters' fast path
        // already skips the timing when nothing observes, but QueryRecorder
        // (ai:observe, profiling, tests) also takes the measured branch — and
        // then this method is reached with logging switched off.
        if ($threshold <= 0 || $milliseconds < $threshold) {
            return;
        }

        StaticLoggerBridge::warning('orm', 'Slow query.', [
            'ms' => round($milliseconds, 1),
            // mb_* so the cut never lands inside a multi-byte sequence:
            // invalid UTF-8 makes the logger's json_encode() fail and the
            // whole entry disappear — losing the slow query it reports.
            'sql' => mb_strlen($sql) > 500 ? mb_substr($sql, 0, 500) . '…' : $sql,
        ]);
    }

    /** Test seam: reset the memoized threshold so a test can vary the env. */
    public static function resetForTests(): void
    {
        self::$thresholdMs = null;
    }
}

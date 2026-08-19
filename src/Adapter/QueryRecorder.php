<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

/**
 * Per-process switch that lets a development tool see the queries a request ran.
 *
 * ## Why this is not a decorator
 *
 * {@see LoggingAdapter} is the decorator shape, and it cannot do this job. A
 * decorator only observes if it is already in the chain when the adapter is handed
 * out, and adapters are built once per worker and then cached by their consumers —
 * so wrapping at the moment somebody asks to record catches only a worker that has
 * not built one yet. That looks like it works immediately after a restart and
 * records nothing from then on.
 *
 * Wrapping *unconditionally* fixes the timing and breaks something worse: the ORM
 * asks `$adapter instanceof SqliteAdapter` in four places to decide whether a
 * connection needs pooling, and a wrapper is not that type. The transaction
 * manager then reaches for a pool the SQLite adapter does not have.
 *
 * So the adapters record for themselves, through this class. No wrapper, no type
 * hidden from the checks that depend on it.
 *
 * ## Why the state is static
 *
 * A running application holds more than one OrmManager — ConnectionRegistry
 * builds one per named connection — so per-instance state would be switched on
 * for one adapter while the queries flow through another.
 *
 * ## Cost, and what this is not for
 *
 * Off, each query pays one boolean check. On, the log grows without bound and is
 * shared across coroutines, so a recording left running will consume memory and
 * mix queries from concurrent requests together. It exists for a developer
 * inspecting a single request. Nothing in the framework switches it on.
 */
final class QueryRecorder
{
    private static bool $recording = false;

    /**
     * How many traces currently want the log. Counted rather than boolean:
     * two requests traced side by side on one worker share this switch, and
     * without the count the first one to finish turned recording off underneath
     * the other, silently ending its query capture mid-trace.
     */
    private static int $sessions = 0;

    /** @var list<array{sql: string, params: array<mixed>, timeMs: float}> */
    private static array $log = [];

    /**
     * Called with (sql, params, timeMs) as each query completes, while recording
     * is on. One slot, not a list: the only registered observer is stateless
     * (it resolves the current coroutine's trace buffer at call time), so which
     * trace registered it is irrelevant. See {@see observe()}.
     */
    private static ?\Closure $observer = null;

    /**
     * Begin recording. The leftover log is discarded only when no other session
     * is live, so a trace cannot inherit queries from an earlier request but
     * also cannot wipe a concurrent one's capture.
     */
    public static function start(): void
    {
        if (self::$sessions === 0) {
            self::$log = [];
        }
        self::$sessions++;
        self::$recording = true;
    }

    /**
     * Release one session; recording stops and the log is discarded when the
     * last session ends, so the unbounded log does not survive in a worker that
     * lives for days.
     */
    public static function stop(): void
    {
        self::$sessions = max(0, self::$sessions - 1);
        if (self::$sessions === 0) {
            self::$recording = false;
            self::$log = [];
            self::$observer = null;
        }
    }

    /**
     * Attach a live observer, letting a tracer place each query on its timeline
     * as it happens instead of draining a positionless list at the end. Only
     * effective while recording; cleared when the last session stops.
     */
    public static function observe(?\Closure $observer): void
    {
        self::$observer = $observer;
    }

    public static function isRecording(): bool
    {
        return self::$recording;
    }

    /**
     * Record one executed query. A no-op unless recording, which is the state
     * every production process stays in.
     *
     * @param array<mixed> $params
     */
    public static function record(string $sql, array $params, float $timeMs): void
    {
        if (!self::$recording) {
            return;
        }

        self::$log[] = ['sql' => $sql, 'params' => $params, 'timeMs' => $timeMs];

        if (self::$observer !== null) {
            try {
                (self::$observer)($sql, $params, $timeMs);
            } catch (\Throwable) {
                // An observer that throws must not break the query it observes;
                // detach it so the failure cannot repeat on every statement.
                self::$observer = null;
            }
        }
    }

    /**
     * Read and clear.
     *
     * @return list<array{sql: string, params: array<mixed>, timeMs: float}>
     */
    public static function drain(): array
    {
        $out = self::$log;
        self::$log = [];

        return $out;
    }
}

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

    /** @var list<array{sql: string, params: array<mixed>, timeMs: float}> */
    private static array $log = [];

    /**
     * Begin recording, discarding anything left behind, so a trace cannot inherit
     * queries from an earlier request.
     */
    public static function start(): void
    {
        self::$log = [];
        self::$recording = true;
    }

    /**
     * Stop and discard. Called at the end of a traced request so the unbounded
     * log does not survive in a worker that lives for days.
     */
    public static function stop(): void
    {
        self::$recording = false;
        self::$log = [];
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

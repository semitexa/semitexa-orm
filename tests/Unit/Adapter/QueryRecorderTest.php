<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\QueryRecorder;

/**
 * The recorder is a worker-global switch shared by every trace on the worker,
 * so what matters is the session arithmetic (one trace closing must not end a
 * concurrent one's capture) and that the live observer can neither leak nor
 * break the query it observes.
 */
final class QueryRecorderTest extends TestCase
{
    protected function setUp(): void
    {
        // Drain any state a previous test left; stop() clamps at zero.
        for ($i = 0; $i < 5; $i++) {
            QueryRecorder::stop();
        }
    }

    protected function tearDown(): void
    {
        for ($i = 0; $i < 5; $i++) {
            QueryRecorder::stop();
        }
    }

    #[Test]
    public function one_session_closing_does_not_end_a_concurrent_one(): void
    {
        QueryRecorder::start(); // trace A
        QueryRecorder::start(); // trace B, same worker

        QueryRecorder::stop();  // A finishes first

        self::assertTrue(QueryRecorder::isRecording(), 'closing trace A must not stop trace B\'s capture');

        QueryRecorder::stop();
        self::assertFalse(QueryRecorder::isRecording(), 'the last session ends recording');
    }

    #[Test]
    public function the_observer_sees_each_query_while_recording(): void
    {
        $seen = [];
        QueryRecorder::start();
        QueryRecorder::observe(function (string $sql, array $params, float $timeMs) use (&$seen): void {
            $seen[] = [$sql, count($params), $timeMs];
        });

        QueryRecorder::record('SELECT 1', ['x'], 2.5);

        self::assertSame([['SELECT 1', 1, 2.5]], $seen);
    }

    #[Test]
    public function the_observer_is_silent_when_not_recording(): void
    {
        $seen = 0;
        QueryRecorder::start();
        QueryRecorder::observe(function () use (&$seen): void {
            $seen++;
        });
        QueryRecorder::stop();

        QueryRecorder::record('SELECT 1', [], 1.0);

        self::assertSame(0, $seen, 'a query outside any session must not reach a stale observer');
    }

    #[Test]
    public function an_observer_registered_outside_a_session_is_refused(): void
    {
        // A stale callback surviving into the NEXT session would receive
        // another request's SQL and bindings — the registration must be part
        // of opening a session, never a standing hook.
        $seen = 0;
        QueryRecorder::observe(function () use (&$seen): void {
            $seen++;
        });

        QueryRecorder::start();
        QueryRecorder::record('SELECT 1', [], 1.0);
        QueryRecorder::stop();

        self::assertSame(0, $seen, 'an out-of-session observer must never see a later session\'s queries');
    }

    #[Test]
    public function a_throwing_observer_detaches_and_the_query_still_logs(): void
    {
        QueryRecorder::start();
        QueryRecorder::observe(function (): void {
            throw new \RuntimeException('observer bug');
        });

        QueryRecorder::record('SELECT 1', [], 1.0);
        QueryRecorder::record('SELECT 2', [], 1.0);

        self::assertCount(2, QueryRecorder::drain(), 'the log must survive a broken observer');
    }
}

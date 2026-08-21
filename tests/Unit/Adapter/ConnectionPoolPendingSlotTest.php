<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\ConnectionPool;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;

/**
 * The borrow registry can only record a connection that exists. Under Swoole
 * hooks the factory suspends (TCP connect plus auth), so a coroutine killed
 * inside that window leaves the slot claimed by cmpset with no PDO to
 * reclaim — the same ratcheting leak the registry prevents, entered through a
 * different door. The claim is therefore tracked from before the create.
 */
final class ConnectionPoolPendingSlotTest extends TestCase
{
    #[Test]
    public function a_slot_claimed_for_a_connection_that_never_opened_is_released(): void
    {
        if (!class_exists(\Swoole\Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }

        Coroutine\run(function () {
            $entered = new \ArrayObject();

            // A factory that suspends forever: it models a connect that never
            // completes before its coroutine is killed.
            $pool = new ConnectionPool(1, static function () use ($entered): \PDO {
                $entered[] = true;
                Coroutine::sleep(30);

                return new \PDO('sqlite::memory:');
            });

            $wg = new WaitGroup();
            $wg->add(1);
            $cid = Coroutine::create(function () use ($pool, $wg) {
                try {
                    $pool->pop();
                } finally {
                    $wg->done();
                }
            });

            // Let the child reach the suspending factory, then kill it there.
            Coroutine::sleep(0.05);
            self::assertCount(1, $entered, 'Precondition: the child must be parked inside the factory.');
            Coroutine::cancel($cid);
            $wg->wait();

            // The slot must be back: a size-1 pool can otherwise never serve
            // another caller, because `created` still counts the dead claim.
            $conn = $pool->pop(1.0);
            self::assertInstanceOf(\PDO::class, $conn);
            self::assertGreaterThanOrEqual(
                1,
                $pool->getStats()['reclaimed_from_dead_coroutines'],
                'the abandoned claim must be counted as a reclaim',
            );
            $pool->push($conn);

            $pool->close();
        });
    }
}

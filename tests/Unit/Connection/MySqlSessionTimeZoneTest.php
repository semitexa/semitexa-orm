<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Connection;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\MySqlSessionTimeZone;

/**
 * TIMESTAMP columns convert through the session zone; TypeCaster binds UTC,
 * so every session must be UTC or a non-UTC server stores the wrong instant.
 */
final class MySqlSessionTimeZoneTest extends TestCase
{
    #[Test]
    public function the_session_is_pinned_to_utc_and_the_connection_is_handed_back(): void
    {
        // A mock needs no PDO driver at all.
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects(self::once())
            ->method('exec')
            ->with("SET time_zone = '+00:00'")
            ->willReturn(0);

        self::assertSame($pdo, MySqlSessionTimeZone::applyTo($pdo));
    }
}

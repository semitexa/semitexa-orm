<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Integration\Dialect;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\MysqlDialect;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Tests\Support\RequiresDatabase;

/**
 * SqlDialect::now() is UTC whatever the session's time zone (review, orm#115):
 * NOW() followed a session set to +05:00 and came back five hours ahead.
 */
final class MysqlDialectNowTest extends TestCase
{
    use RequiresDatabase;

    private OrmManager $orm;

    protected function setUp(): void
    {
        $this->orm = self::mysqlOrm('UTC_TIMESTAMP() is MySQL\'s');
    }

    protected function tearDown(): void
    {
        if (isset($this->orm)) {
            $this->orm->shutdown();
        }
    }

    #[Test]
    public function now_is_utc_in_a_session_that_is_not(): void
    {
        $dialect = new MysqlDialect();
        $this->orm->getAdapter()->execute("SET time_zone = '+05:00'");
        // The zone is read in the same statement: a pooled adapter may hand the SELECT another
        // connection, and on one at +00:00 NOW() would pass too (review, orm#115).
        $row = $this->orm->getAdapter()->query('SELECT @@session.time_zone AS tz, ' . $dialect->now() . ' AS n, ' . $dialect->now(30) . ' AS later')->rows[0];
        self::assertSame('+05:00', $row['tz'], 'the SELECT ran in the +05:00 session');

        $now = (new \DateTimeImmutable((string) $row['n'], new \DateTimeZone('UTC')))->getTimestamp();
        $later = (new \DateTimeImmutable((string) $row['later'], new \DateTimeZone('UTC')))->getTimestamp();
        self::assertLessThan(5, abs($now - time()), 'now() in UTC, not the session\'s +05:00');
        self::assertSame(30, $later - $now);
    }
}

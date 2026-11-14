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
        // One connection for the SET and the SELECT: a pooled adapter hands each statement its own,
        // which the release preflight's full suite did (it read +00:00). A transaction holds one,
        // and the zone is put back before it goes back to the pool.
        $row = $this->orm->getTransactionManager()->run(function () use ($dialect): array {
            $db = $this->orm->getQueryAdapter();
            $db->execute("SET time_zone = '+05:00'");
            try {
                return $db->execute('SELECT @@session.time_zone AS tz, ' . $dialect->now() . ' AS n, ' . $dialect->now(30) . ' AS later')->rows[0];
            } finally {
                $db->execute("SET time_zone = '+00:00'");
            }
        });
        // The zone read in the same statement: a SELECT on a +00:00 connection would let NOW() pass too.
        self::assertSame('+05:00', $row['tz'], 'the SELECT ran in the +05:00 session');

        $now = (new \DateTimeImmutable((string) $row['n'], new \DateTimeZone('UTC')))->getTimestamp();
        $later = (new \DateTimeImmutable((string) $row['later'], new \DateTimeZone('UTC')))->getTimestamp();
        self::assertLessThan(5, abs($now - time()), 'now() in UTC, not the session\'s +05:00');
        self::assertSame(30, $later - $now);
    }
}

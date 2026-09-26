<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Hydration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Application\Service\Hydration\TypeCaster;
use Semitexa\Orm\Domain\Model\ColumnDefinition;

/**
 * A datetime column stores no offset. The instant must survive the round trip
 * whatever offset the value carried: +05:00 used to be written as its own
 * wall clock and read back as the default zone, five hours off.
 */
final class DateTimeZoneRoundTripTest extends TestCase
{
    private string $previousZone;

    protected function setUp(): void
    {
        $this->previousZone = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousZone);
    }

    #[Test]
    public function a_value_with_an_offset_comes_back_as_the_same_instant(): void
    {
        $caster = new TypeCaster();
        $column = new ColumnDefinition(name: 'at', type: MySqlType::Datetime, phpType: \DateTimeImmutable::class);
        $written = new \DateTimeImmutable('2026-06-01T09:00:00+05:00');

        $stored = $caster->castToDb($written, $column);
        $read = $caster->castFromDb($stored, $column);

        self::assertSame('2026-06-01 04:00:00', $stored);
        self::assertInstanceOf(\DateTimeImmutable::class, $read);
        self::assertSame($written->getTimestamp(), $read->getTimestamp());
    }

    #[Test]
    public function the_repeated_daylight_saving_hour_round_trips_under_a_non_utc_default_zone(): void
    {
        // 01:30 happens twice in New York on 2026-11-01. Written as that wall
        // clock, the second occurrence could not be told from the first.
        date_default_timezone_set('America/New_York');
        $caster = new TypeCaster();

        foreach ([MySqlType::Datetime, MySqlType::Timestamp] as $type) {
            $column = new ColumnDefinition(name: 'at', type: $type, phpType: \DateTimeImmutable::class);
            foreach (['2026-11-01T01:30:00-04:00', '2026-11-01T01:30:00-05:00'] as $instant) {
                $written = new \DateTimeImmutable($instant);

                $read = $caster->castFromDb($caster->castToDb($written, $column), $column);

                self::assertInstanceOf(\DateTimeImmutable::class, $read);
                self::assertSame($written->getTimestamp(), $read->getTimestamp(), $instant);
            }
        }
    }

    #[Test]
    public function a_date_column_keeps_the_calendar_day_it_was_given(): void
    {
        $column = new ColumnDefinition(name: 'on', type: MySqlType::Date, phpType: \DateTimeImmutable::class);

        self::assertSame('2026-06-01', (new TypeCaster())->castToDb(new \DateTimeImmutable('2026-06-01T01:00:00+05:00'), $column));
    }
}

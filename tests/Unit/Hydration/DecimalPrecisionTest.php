<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Hydration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Adapter\SqliteType;
use Semitexa\Orm\Application\Service\Hydration\TypeCaster;
use Semitexa\Orm\Domain\Model\ColumnDefinition;

/**
 * A DECIMAL is exact. Its column pass went through a float, so a `string`
 * price stored as 19.90 came back "19.9", and an amount past ~15 significant
 * digits came back changed.
 */
final class DecimalPrecisionTest extends TestCase
{
    #[Test]
    public function a_decimal_reaches_a_string_property_digit_for_digit(): void
    {
        $caster = new TypeCaster();
        $column = new ColumnDefinition(name: 'price', type: MySqlType::Decimal, phpType: 'string');

        self::assertSame('19.90', $caster->castToPropertyType($caster->castFromDb('19.90', $column), 'string', false));
        self::assertSame('12345678901234567.89', $caster->castToPropertyType($caster->castFromDb('12345678901234567.89', $column), 'string', false));
        self::assertSame('-0.01', $caster->castFromDb('-0.01', $column));
    }

    #[Test]
    public function a_float_property_still_gets_a_float(): void
    {
        $caster = new TypeCaster();
        $column = new ColumnDefinition(name: 'funds', type: MySqlType::Decimal, phpType: 'float');

        self::assertSame(19.9, $caster->castToPropertyType($caster->castFromDb('19.90', $column), 'float', false));
    }

    #[Test]
    public function a_float_the_driver_already_made_is_written_out_without_an_exponent(): void
    {
        $column = new ColumnDefinition(name: 'price', type: SqliteType::Decimal, phpType: 'string');

        self::assertSame('0.00001', (new TypeCaster())->castFromDb(0.00001, $column));
        self::assertSame('19.9', (new TypeCaster())->castFromDb(19.9, $column));
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Hydration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Application\Service\Hydration\TypeCaster;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Orm\Domain\Model\ColumnDefinition;
use Semitexa\Orm\Adapter\MySqlType;

/**
 * What the ORM converts on its own, and what it converts only because a
 * resource model asked.
 *
 * {@see \Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface} tells every
 * mapper author which of the two they are looking at, and a mapper that reads
 * that wrong is not a style problem: one that repeated the first pass took down
 * every scheduled job in a consumer. So the distinction is pinned here rather
 * than left in a docblock nothing checks.
 */
final class TypeCasterOwnershipTest extends TestCase
{
    private TypeCaster $caster;

    protected function setUp(): void
    {
        $this->caster = new TypeCaster();
    }

    private function column(MySqlType $type, string $phpType): ColumnDefinition
    {
        return new ColumnDefinition(name: 'value', type: $type, phpType: $phpType);
    }

    /**
     * The first pass reads the COLUMN and is the same for every resource model.
     *
     * This is the one a mapper must never repeat, and the reason it must not:
     * the bytes are gone by the time a mapper sees them, so converting again
     * is handed 36 characters and throws.
     */
    #[Test]
    public function a_binary_16_column_becomes_a_uuid_string_whatever_the_model_declares(): void
    {
        $uuid = Uuid7::generate();
        $bytes = Uuid7::toBytes($uuid);

        foreach (['string', 'mixed'] as $declared) {
            self::assertSame(
                $uuid,
                $this->caster->castFromDb($bytes, $this->column(MySqlType::Binary, $declared)),
                'the declared type does not change this conversion; the column decides it',
            );
        }

        self::assertSame(
            $bytes,
            $this->caster->castToDb($uuid, $this->column(MySqlType::Binary, 'string')),
            'and it goes back the same way, which is why a mapper needs no toBytes() either',
        );
    }

    /**
     * The second pass casts to what the RESOURCE MODEL declared — and the two
     * passes are exercised in the order the hydrator runs them, because in
     * isolation the second one answers a question that never gets asked.
     *
     * The docblock used to promise a DateTimeImmutable to every mapper. It is
     * not the ORM's to promise: the schema validator permits a datetime column
     * to be declared `string` or `mixed`, so a mapper written against that
     * promise would call ->format() on something that is not an object.
     */
    #[Test]
    public function a_datetime_column_arrives_as_the_type_the_model_declared(): void
    {
        $column = $this->column(MySqlType::Datetime, 'DateTimeImmutable');
        $fromDb = $this->caster->castFromDb('2026-09-11 12:30:00', $column);

        self::assertInstanceOf(
            \DateTimeImmutable::class,
            $this->caster->castToPropertyType($fromDb, 'DateTimeImmutable', false),
        );
    }

    /**
     * A datetime column declared `string` now hydrates to a string.
     *
     * This used to be pinned as a DEFECT: the schema validator permits the
     * declaration, the column pass turns the value into a DateTimeImmutable
     * before the property pass is consulted, and the string branch cast it with
     * `(string) $value`, which an object that is not Stringable refuses. So a
     * model the validator accepted fatalled on its first read — and only on
     * MySQL, since the SQLite branch of castFromDb() leaves datetimes as
     * strings and the same model worked there.
     *
     * Fixed in the property pass rather than by narrowing the validator,
     * because of what the two passes are FOR: the first reads the column, the
     * second delivers what the model declared. It could not deliver a string,
     * and that is a hole in the second pass — not a promise the validator
     * should not have made. Narrowing it would also have turned working SQLite
     * models into boot failures.
     *
     * @see \Semitexa\Orm\Application\Service\Schema\SchemaCollector — permits string/mixed here
     */
    #[Test]
    public function a_datetime_column_declared_as_a_string_arrives_as_a_string(): void
    {
        $column = $this->column(MySqlType::Datetime, 'string');
        $fromDb = $this->caster->castFromDb('2026-09-11 12:30:00', $column);

        self::assertInstanceOf(
            \DateTimeImmutable::class,
            $fromDb,
            'the column pass still converts before the property pass is consulted',
        );

        self::assertSame(
            '2026-09-11 12:30:00',
            $this->caster->castToPropertyTypeForColumn($fromDb, 'string', false, $column),
            'what was stored is what comes back',
        );
    }

    /**
     * The format follows the COLUMN, exactly as castToDb() chooses it going the
     * other way. A date column that came back with a time on it would be a
     * value the application never wrote.
     */
    #[Test]
    public function the_string_form_follows_the_column_type(): void
    {
        $moment = new \DateTimeImmutable('2026-09-11 12:30:00');

        self::assertSame(
            '2026-09-11',
            $this->caster->castToPropertyTypeForColumn($moment, 'string', false, $this->column(MySqlType::Date, 'string')),
        );
        self::assertSame(
            '12:30:00',
            $this->caster->castToPropertyTypeForColumn($moment, 'string', false, $this->column(MySqlType::Time, 'string')),
        );
        self::assertSame(
            '2026-09-11 12:30:00',
            $this->caster->castToPropertyTypeForColumn($moment, 'string', false, $this->column(MySqlType::Timestamp, 'string')),
        );
    }

    /**
     * Called without a column — the hydrator always passes one, but this is a
     * public method — a datetime still has to produce a string rather than the
     * fatal it produced before. The datetime form is the default because it is
     * the one castToDb() writes for everything that is not a date or a time.
     */
    #[Test]
    public function a_datetime_still_becomes_a_string_when_no_column_is_given(): void
    {
        self::assertSame(
            '2026-09-11 12:30:00',
            $this->caster->castToPropertyType(new \DateTimeImmutable('2026-09-11 12:30:00'), 'string', false),
        );
    }

    /** A round trip through the database leaves the string it started as. */
    #[Test]
    public function a_string_survives_a_round_trip_through_a_datetime_column(): void
    {
        $column = $this->column(MySqlType::Datetime, 'string');
        $stored = '2026-09-11 12:30:00';

        $toDb = $this->caster->castToDb($stored, $column);
        $back = $this->caster->castToPropertyTypeForColumn($this->caster->castFromDb($toDb, $column), 'string', $column->nullable, $column);

        self::assertSame($stored, $back);
    }

    /**
     * Only DateTimeInterface is intercepted. An object that CAN become a string
     * says so, and casting it was never the bug.
     */
    #[Test]
    public function a_stringable_object_is_still_cast_the_ordinary_way(): void
    {
        $stringable = new class () implements \Stringable {
            public function __toString(): string
            {
                return 'i know how to do this';
            }
        };

        self::assertSame(
            'i know how to do this',
            $this->caster->castToPropertyType($stringable, 'string', false),
        );
    }

    /** `mixed` asked for anything, so it keeps the object the column produced. */
    #[Test]
    public function a_datetime_column_declared_mixed_keeps_the_object(): void
    {
        $column = $this->column(MySqlType::Datetime, 'mixed');
        $fromDb = $this->caster->castFromDb('2026-09-11 12:30:00', $column);

        self::assertInstanceOf(
            \DateTimeImmutable::class,
            $this->caster->castToPropertyTypeForColumn($fromDb, 'mixed', false, $column),
        );
    }

    /** Same for an enum: a backed case only where the property is typed as one. */
    #[Test]
    public function an_enum_column_arrives_as_a_case_only_where_one_was_declared(): void
    {
        self::assertSame(
            'draft',
            $this->caster->castToPropertyType('draft', 'string', false),
            'nothing turns this into an enum; no property asked for one',
        );
    }

    /**
     * The public three-argument signature is an extension point: TypeCaster is
     * not final and the hydrator accepts an injected instance, so an
     * application may already override it. Widening that method would have made
     * such a subclass incompatible with its parent and fatalled the class at
     * load; the column-aware work lives in its own method instead.
     */
    #[Test]
    public function a_subclass_overriding_the_three_argument_method_still_loads(): void
    {
        $caster = new class () extends TypeCaster {
            public function castToPropertyType(mixed $value, string $phpType, bool $nullable): mixed
            {
                return $phpType === 'string' ? 'from the subclass' : parent::castToPropertyType($value, $phpType, $nullable);
            }
        };

        self::assertSame('from the subclass', $caster->castToPropertyType('x', 'string', false));
        self::assertSame(
            'from the subclass',
            $caster->castToPropertyTypeForColumn('x', 'string', false, $this->column(MySqlType::Varchar, 'string')),
            'and the column-aware path still goes through it for everything it did not take over',
        );
    }

    /**
     * Including the datetime case, which is the one an application is most
     * likely to have overridden this method for. The column-aware entry point
     * answered it directly for a while, so a caster written to read dates in
     * its own format silently stopped being asked — the values it existed to
     * handle were the only ones it never saw. Raised in review of orm#67.
     */
    #[Test]
    public function a_subclass_still_decides_how_a_datetime_becomes_a_string(): void
    {
        $caster = new class () extends TypeCaster {
            public function castToPropertyType(mixed $value, string $phpType, bool $nullable): mixed
            {
                if ($value instanceof \DateTimeInterface && $phpType === 'string') {
                    return $value->format(\DateTimeInterface::ATOM);
                }

                return parent::castToPropertyType($value, $phpType, $nullable);
            }
        };

        $value = new \DateTimeImmutable('2026-09-13 05:41:07', new \DateTimeZone('UTC'));

        self::assertSame(
            '2026-09-13T05:41:07+00:00',
            $caster->castToPropertyTypeForColumn($value, 'string', false, $this->column(MySqlType::Datetime, 'string')),
            'the override is the whole reason the application wrote one',
        );
    }

    /**
     * And the column still reaches the built-in formatting when nobody has
     * overridden anything — the reason the column-aware entry point exists.
     */
    #[Test]
    public function the_column_still_decides_the_format_underneath(): void
    {
        $value = new \DateTimeImmutable('2026-09-13 05:41:07', new \DateTimeZone('UTC'));

        self::assertSame(
            '2026-09-13',
            $this->caster->castToPropertyTypeForColumn($value, 'string', false, $this->column(MySqlType::Date, 'string')),
        );
        self::assertSame(
            '2026-09-13 05:41:07',
            $this->caster->castToPropertyTypeForColumn($value, 'string', false, $this->column(MySqlType::Datetime, 'string')),
        );
    }

    /** The remembered column must not outlive the call that supplied it. */
    #[Test]
    public function the_column_does_not_leak_into_the_next_cast(): void
    {
        $value = new \DateTimeImmutable('2026-09-13 05:41:07', new \DateTimeZone('UTC'));

        $this->caster->castToPropertyTypeForColumn($value, 'string', false, $this->column(MySqlType::Date, 'string'));

        self::assertSame(
            '2026-09-13 05:41:07',
            $this->caster->castToPropertyType($value, 'string', false),
            'a bare call has no column and must get the default form, not the last one seen',
        );
    }
}

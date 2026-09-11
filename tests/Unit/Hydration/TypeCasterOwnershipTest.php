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
     * A datetime column declared `string` is accepted by the schema validator
     * and then fails on the first read. PINNED AS A DEFECT, not as a rule.
     *
     * The first version of this test called castToPropertyType() with a raw
     * string and concluded that `string` works. It does — but the hydrator
     * never hands it one: castFromDb() has already produced a
     * DateTimeImmutable, and the string branch casts with `(string) $value`,
     * which an object that is not Stringable refuses. So the test passed while
     * describing a path that does not occur, which is worse than no test.
     *
     * Nothing in this repository declares such a column, which is why the
     * contradiction between the validator and the caster has gone unnoticed.
     * This pins the real behaviour so the day it is fixed, it is fixed
     * deliberately and this test is what says so.
     *
     * @see \Semitexa\Orm\Application\Service\Schema\SchemaCollector — permits string/mixed here
     */
    #[Test]
    public function a_datetime_column_declared_as_a_string_cannot_be_hydrated_today(): void
    {
        $column = $this->column(MySqlType::Datetime, 'string');
        $fromDb = $this->caster->castFromDb('2026-09-11 12:30:00', $column);

        self::assertInstanceOf(
            \DateTimeImmutable::class,
            $fromDb,
            'the column pass converts before the property pass is ever consulted',
        );

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('DateTimeImmutable could not be converted to string');

        $this->caster->castToPropertyType($fromDb, 'string', false);
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
}

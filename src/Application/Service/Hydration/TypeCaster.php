<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service\Hydration;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Adapter\SqliteType;
use Semitexa\Orm\Domain\Model\ColumnDefinition;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Core\Support\CoroutineLocal;

class TypeCaster
{
    /**
     * Where the column for the current property cast is kept — see
     * {@see castToPropertyTypeForColumn()}.
     *
     * Per COROUTINE, not per instance. An injected caster is shared by the
     * whole worker, and the method it hands the value to is the overridable
     * one: an application's override may do I/O, and a coroutine that suspends
     * there lets another hydration overwrite an instance field underneath it.
     * The resumed call would then format by the other request's column —
     * dropping a time from a datetime, or adding one to a date — and the
     * interleaved finally blocks would restore each other's value. Raised in
     * review of orm#67.
     *
     * Per INSTANCE as well. An override may delegate to another caster's public
     * three-argument method, which was given no column and documents the
     * datetime default — under one process-wide key it inherited the outer
     * caster's DATE instead and dropped the time. Two independently composed
     * casters could read each other's context the same way.
     */
    private const COLUMN_KEY = 'orm.type_caster.column.';

    /**
     * Cast a raw DB value to the expected PHP type based on column definition.
     */
    public function castFromDb(mixed $value, ColumnDefinition $column): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($column->type) {
            MySqlType::TinyInt, MySqlType::SmallInt,
            MySqlType::Int, MySqlType::Bigint, MySqlType::Year,
            SqliteType::TinyInt, SqliteType::SmallInt,
            SqliteType::Int, SqliteType::Bigint               => (int) $value,
            MySqlType::Float, MySqlType::Double,
            MySqlType::Decimal,
            SqliteType::Float, SqliteType::Double,
            SqliteType::Decimal                               => (float) $value,
            MySqlType::Boolean,
            SqliteType::Boolean                               => (bool) $value,
            MySqlType::Varchar, MySqlType::Char,
            MySqlType::Text, MySqlType::MediumText,
            MySqlType::LongText, MySqlType::Time,
            MySqlType::Json,
            SqliteType::Varchar, SqliteType::Char,
            SqliteType::Text, SqliteType::Time,
            SqliteType::Datetime, SqliteType::Date,
            SqliteType::Json                                  => (string) $value,
            MySqlType::Blob, SqliteType::Blob                 => $value, // raw bytes
            MySqlType::Binary, SqliteType::Binary             => is_string($value) && strlen($value) === 16
                ? Uuid7::fromBytes($value)
                : $value,
            MySqlType::Datetime, MySqlType::Timestamp,
            MySqlType::Date                                   => $this->castToDateTime($value),
            default                                           => $value,
        };
    }

    /**
     * Cast a raw DB value to the PHP type expected by the property.
     * Handles enums, DateTimeImmutable, and scalars.
     */
    public function castToPropertyType(mixed $value, string $phpType, bool $nullable): mixed
    {
        if ($value === null) {
            return null;
        }

        // Scalar types
        return match ($phpType) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => (bool) $value,
            'string' => match (true) {
                is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE) ?: '[]',
                // The column pass has already turned a datetime column into a
                // DateTimeImmutable, and `(string)` on one is a fatal — so a
                // model that declared `string`, which the schema validator
                // permits, could not be hydrated at all. Formatting here is the
                // second pass doing its job: deliver what the MODEL declared.
                // The format follows the column, exactly as castToDb() chooses
                // it going the other way, so what was written comes back — to
                // the second; see formatForColumn() on sub-second precision.
                $value instanceof \DateTimeInterface => $this->formatForColumn($value, $this->columnInPlay()),
                default => (string) $value,
            },
            'array' => is_array($value) ? $value : json_decode((string) $value, true),
            'DateTimeImmutable', '\DateTimeImmutable' => $value instanceof \DateTimeImmutable
                ? $value
                : new \DateTimeImmutable((string) $value),
            'DateTime', '\DateTime' => $value instanceof \DateTime
                ? $value
                : new \DateTime((string) $value),
            default => $this->castToEnum($value, $phpType),
        };
    }

    /**
     * The string a datetime becomes for a given column — the same choice
     * castToDb() makes, kept in one place so the two directions cannot drift.
     *
     * Without a column — castToPropertyType() is public and can be called
     * directly, rather than through the column-aware entry point that supplies
     * one — the datetime form is the default, because it is what castToDb()
     * writes for everything that is not a date or a time.
     *
     * SECOND precision, in both directions. A DATETIME(6) column read into a
     * `string` property loses its microseconds here — and castToDb() writes the
     * same truncated form, so a read-modify-write discards them for good. A
     * model that needs them should declare DateTimeImmutable, which is handed
     * the object untouched.
     */
    private function formatForColumn(\DateTimeInterface $value, ?ColumnDefinition $column): string
    {
        return match ($column?->type) {
            MySqlType::Date, SqliteType::Date => $value->format('Y-m-d'),
            MySqlType::Time, SqliteType::Time => $value->format('H:i:s'),
            default                           => $value->format('Y-m-d H:i:s'),
        };
    }

    /**
     * The property pass, told which column the value came from.
     *
     * SEPARATE from {@see castToPropertyType()} on purpose. That method is
     * public on a non-final class and the hydrator accepts an injected
     * instance, so an application's subclass may already override it with the
     * three-argument signature — adding a fourth parameter there would make
     * that declaration incompatible with its parent and fatal the class at
     * load.
     *
     * It does not do the casting itself, though. It remembers the column and
     * hands the value to the overridable method, so a subclass that overrides
     * castToPropertyType() — to read a date in its own format, say — still
     * sees every value and still decides. Answering datetimes here directly
     * took that decision away from it silently, which is worse than the
     * signature break it was avoiding. Raised in review of orm#67.
     */
    public function castToPropertyTypeForColumn(
        mixed $value,
        string $phpType,
        bool $nullable,
        ColumnDefinition $column,
    ): mixed {
        $key = $this->columnKey();
        $previous = $this->columnInPlay();
        CoroutineLocal::set($key, $column);

        try {
            return $this->castToPropertyType($value, $phpType, $nullable);
        } finally {
            // Restored rather than cleared: casts nest, and the outer one is
            // still owed its own column.
            if ($previous === null) {
                CoroutineLocal::remove($key);
            } else {
                CoroutineLocal::set($key, $previous);
            }
        }
    }

    /** The column THIS caster is currently casting for in THIS coroutine, if it was given one. */
    private function columnInPlay(): ?ColumnDefinition
    {
        $column = CoroutineLocal::get($this->columnKey());

        return $column instanceof ColumnDefinition ? $column : null;
    }

    private function columnKey(): string
    {
        return self::COLUMN_KEY . spl_object_id($this);
    }

    /**
     * Cast a PHP value to a format suitable for DB binding.
     */
    public function castToDb(mixed $value, ColumnDefinition $column): mixed
    {
        if ($value === null) {
            return null;
        }

        // Enum → backed value
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        // DateTimeImmutable / DateTime → string
        if ($value instanceof \DateTimeInterface) {
            return match ($column->type) {
                MySqlType::Date, SqliteType::Date => $value->format('Y-m-d'),
                MySqlType::Time, SqliteType::Time => $value->format('H:i:s'),
                default                           => $value->format('Y-m-d H:i:s'),
            };
        }

        // Array → JSON
        if (is_array($value) && ($column->type === MySqlType::Json || $column->type === SqliteType::Json)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        // UUID string → binary for BINARY columns
        if (($column->type === MySqlType::Binary || $column->type === SqliteType::Binary)
            && is_string($value)
            && strlen($value) === 36
            && str_contains($value, '-')
        ) {
            return Uuid7::toBytes($value);
        }

        // Boolean → int
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return $value;
    }

    private function castToDateTime(mixed $value): \DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        return new \DateTimeImmutable((string) $value);
    }
    /**
     * Cache: phpType → true (backed enum) | false (not a backed enum).
     * Populated on first encounter; ReflectionEnum is never constructed twice
     * for the same type, regardless of how many rows are hydrated.
     *
     * @var array<string, bool>
     */
    private static array $enumCache = [];

    private function castToEnum(mixed $value, string $phpType): mixed
    {
        if (!isset(self::$enumCache[$phpType])) {
            self::$enumCache[$phpType] = enum_exists($phpType)
                && (new \ReflectionEnum($phpType))->isBacked();
        }

        if (!self::$enumCache[$phpType]) {
            return $value;
        }

        return $phpType::from($value);
    }
}

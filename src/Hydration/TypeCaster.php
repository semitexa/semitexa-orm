<?php

declare(strict_types=1);

namespace Semitexa\Orm\Hydration;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Adapter\SqliteType;
use Semitexa\Orm\Schema\ColumnDefinition;
use Semitexa\Orm\Uuid\Uuid7;

class TypeCaster
{
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
            'string' => is_array($value)
                ? (json_encode($value, JSON_UNESCAPED_UNICODE) ?: '[]')
                : (string) $value,
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

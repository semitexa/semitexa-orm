<?php

declare(strict_types=1);

namespace Semitexa\Orm\Hydration;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Schema\ColumnDefinition;

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
            MySqlType::Int, MySqlType::Bigint => (int) $value,
            MySqlType::Decimal => (float) $value,
            MySqlType::Boolean => (bool) $value,
            MySqlType::Varchar, MySqlType::Text => (string) $value,
            MySqlType::Datetime, MySqlType::Timestamp, MySqlType::Date => $this->castToDateTime($value),
            MySqlType::Json => $this->castToArray($value),
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
            'string' => (string) $value,
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
                MySqlType::Date => $value->format('Y-m-d'),
                default => $value->format('Y-m-d H:i:s'),
            };
        }

        // Array → JSON
        if (is_array($value) && $column->type === MySqlType::Json) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
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

    private function castToArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return json_decode((string) $value, true) ?? [];
    }

    private function castToEnum(mixed $value, string $phpType): mixed
    {
        if (!enum_exists($phpType)) {
            return $value;
        }

        $ref = new \ReflectionEnum($phpType);
        if (!$ref->isBacked()) {
            return $value;
        }

        return $phpType::from($value);
    }
}

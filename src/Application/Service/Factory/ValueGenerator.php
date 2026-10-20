<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service\Factory;

use Random\Engine\Mt19937;
use Random\Randomizer;
use Semitexa\Orm\Metadata\ColumnMetadata;

/**
 * A valid value for a column, from its metadata: the PHP type it holds, its
 * database type, length, precision and scale. Seeded, so a seed gives the same
 * values every run; the row number goes into strings, so a sequence of rows
 * does not trip a unique index.
 */
final class ValueGenerator
{
    private const WORDS = ['amber', 'birch', 'cedar', 'delta', 'ember', 'fjord', 'grove', 'harbor', 'iris', 'juniper', 'kestrel', 'lumen'];
    private const EPOCH = '2026-01-01 00:00:00';

    private readonly Randomizer $random;

    public function __construct(int $seed = 1)
    {
        $this->random = new Randomizer(new Mt19937($seed));
    }

    public function for(ColumnMetadata $column, int $row): mixed
    {
        $type = $column->type instanceof \UnitEnum ? $column->type->name : '';
        $phpType = ltrim($column->phpType, '?\\');

        if (enum_exists($phpType)) {
            $cases = $phpType::cases();

            return $cases[$this->random->getInt(0, count($cases) - 1)];
        }
        if (is_a($phpType, \DateTimeInterface::class, true)) {
            return (new \DateTimeImmutable(self::EPOCH, new \DateTimeZone('UTC')))->modify('+' . $this->random->getInt(0, 525_600) . ' minutes');
        }

        return match ($phpType) {
            'bool' => $this->random->getInt(0, 1) === 1,
            // The database type bounds it: an int property on a Boolean column
            // is 0 or 1, on a TinyInt at most 127.
            'int' => $type === 'Boolean' ? $this->random->getInt(0, 1) : $this->int($type),
            'float' => $type === 'Decimal' ? (float) $this->decimal($column) : round($this->random->getFloat(0, 1000), 2),
            'array' => [],
            default => $this->string($column, $type, $row),
        };
    }

    private function int(string $type): int
    {
        return match ($type) {
            'TinyInt' => $this->random->getInt(0, 127),
            'SmallInt' => $this->random->getInt(0, 32_767),
            'Year' => $this->random->getInt(2000, 2030),
            default => $this->random->getInt(0, 1_000_000),
        };
    }

    private function string(ColumnMetadata $column, string $type, int $row): string
    {
        $value = match ($type) {
            'Binary' => $column->length === 16 ? $this->uuid() : $this->random->getBytes(max(1, min(64, $column->length ?? 16))),
            'Blob' => $this->random->getBytes(32),
            'Json' => '{}',
            'Decimal' => $this->decimal($column),
            'Float', 'Double' => (string) round($this->random->getFloat(0, 1000), 2),
            'TinyInt', 'SmallInt', 'Int', 'Bigint', 'Year' => (string) $this->int($type),
            'Boolean' => (string) $this->random->getInt(0, 1),
            'Datetime', 'Timestamp' => (new \DateTimeImmutable(self::EPOCH))->modify('+' . $this->random->getInt(0, 525_600) . ' minutes')->format('Y-m-d H:i:s'),
            'Date' => (new \DateTimeImmutable(self::EPOCH))->modify('+' . $this->random->getInt(0, 365) . ' days')->format('Y-m-d'),
            'Time' => sprintf('%02d:%02d:00', $this->random->getInt(0, 23), $this->random->getInt(0, 59)),
            default => $this->text($column, $row),
        };

        if ($type === 'Binary' || $type === 'Blob' || $column->length === null) {
            return $value;
        }

        return mb_substr($value, 0, $column->length);
    }

    private function text(ColumnMetadata $column, int $row): string
    {
        if (stripos($column->propertyName, 'email') !== false) {
            return "user{$row}@example.test";
        }
        if (preg_match('/(url|link|href)$/i', $column->propertyName) === 1) {
            return "https://example.test/{$row}";
        }
        $word = self::WORDS[$this->random->getInt(0, count(self::WORDS) - 1)];

        // The row last: cut to a short length, the start keeps it readable,
        // but the row is what keeps values apart — so it goes first when tight.
        return $column->length !== null && $column->length < 12 ? substr("{$row}{$word}", 0, $column->length) : "{$word}-{$row}";
    }

    /** A UUIDv7-shaped string from the seeded generator: the same seed, the same ids. */
    private function uuid(): string
    {
        $bytes = $this->random->getBytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x70);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }

    private function decimal(ColumnMetadata $column): string
    {
        $scale = max(0, $column->scale ?? 2);
        $digits = max(1, ($column->precision ?? 10) - $scale);
        $whole = $this->random->getInt(0, (int) min(PHP_INT_MAX, 10 ** min(15, $digits) - 1));

        return $scale === 0 ? (string) $whole : $whole . '.' . str_pad((string) $this->random->getInt(0, 10 ** min(9, $scale) - 1), $scale, '0', STR_PAD_LEFT);
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Orm\Uuid;

final class Uuid7
{
    /**
     * Generate UUID v7 (RFC 9562): 48-bit unix_ts_ms + 4-bit version + 12-bit rand_a + 2-bit variant + 62-bit rand_b.
     * Returns canonical string: "0192d4e0-7b3a-7xxx-xxxx-xxxxxxxxxxxx"
     */
    public static function generate(): string
    {
        $tsMs = (int) (microtime(true) * 1000);

        // 48-bit timestamp (big-endian)
        $tsHex = str_pad(dechex($tsMs), 12, '0', STR_PAD_LEFT);

        // 12 random bits for rand_a, 62 random bits for rand_b
        $rand = random_bytes(10); // 80 bits total

        // rand_a: first 2 bytes, mask top 4 bits and set version 7
        $rand[0] = chr((ord($rand[0]) & 0x0f) | 0x70);

        // variant: byte at offset 2 (start of rand_b), set top 2 bits to 10
        $rand[2] = chr((ord($rand[2]) & 0x3f) | 0x80);

        $randHex = bin2hex($rand);

        // Format: tttttttt-tttt-7raa-Rbbb-bbbbbbbbbbbb
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($tsHex, 0, 8),
            substr($tsHex, 8, 4),
            substr($randHex, 0, 4),
            substr($randHex, 4, 4),
            substr($randHex, 8, 12),
        );
    }

    /**
     * Canonical UUID string → 16-byte binary.
     */
    public static function toBytes(string $uuid): string
    {
        $hex = str_replace('-', '', $uuid);

        if (strlen($hex) !== 32) {
            throw new \InvalidArgumentException("Invalid UUID string: '{$uuid}'");
        }

        return hex2bin($hex);
    }

    /**
     * 16-byte binary → canonical UUID string.
     */
    public static function fromBytes(string $bytes): string
    {
        if (strlen($bytes) !== 16) {
            throw new \InvalidArgumentException('Expected 16 bytes, got ' . strlen($bytes));
        }

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}

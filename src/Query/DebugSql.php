<?php

declare(strict_types=1);

namespace Semitexa\Orm\Query;

/**
 * A statement with its parameter values written in, for logs and debug output
 * only — never for execution. Out of ResourceModelQuery, which is a recorded
 * structural outlier at its size budget.
 */
final class DebugSql
{
    /** @param array<string, mixed> $params */
    public static function interpolate(string $sql, array $params): string
    {
        $debugSql = '';
        $length = strlen($sql);
        $i = 0;

        // Single-pass replace so interpolated values cannot be rewritten by a
        // later iteration, while quoted/comment regions stay untouched.
        while ($i < $length) {
            $segmentStart = $i;
            if (RawSqlScanner::advancePastQuotedOrCommentRegion($sql, $i, $length)) {
                $debugSql .= substr($sql, $segmentStart, $i - $segmentStart);
                continue;
            }

            if ($sql[$i] === ':') {
                $nameEnd = $i + 1;
                while ($nameEnd < $length && self::isPlaceholderNameChar($sql[$nameEnd])) {
                    $nameEnd++;
                }

                if ($nameEnd > $i + 1) {
                    $name = substr($sql, $i + 1, $nameEnd - $i - 1);
                    $debugSql .= array_key_exists($name, $params)
                        ? self::formatValue($params[$name])
                        : substr($sql, $i, $nameEnd - $i);
                    $i = $nameEnd;
                    continue;
                }
            }

            $debugSql .= $sql[$i];
            $i++;
        }

        return $debugSql;
    }

    private static function isPlaceholderNameChar(string $ch): bool
    {
        return ($ch >= 'a' && $ch <= 'z')
            || ($ch >= 'A' && $ch <= 'Z')
            || ($ch >= '0' && $ch <= '9')
            || $ch === '_';
    }

    private static function formatValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d H:i:s');
        }
        if ($value instanceof \Stringable) {
            $value = (string) $value;
        }
        if (is_string($value)) {
            return "'" . str_replace("'", "''", $value) . "'";
        }
        if (is_scalar($value)) {
            return "'" . str_replace("'", "''", (string) $value) . "'";
        }
        if ($value instanceof \BackedEnum) {
            return "'" . str_replace("'", "''", (string) $value->value) . "'";
        }

        return "'" . str_replace("'", "''", json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: get_debug_type($value)) . "'";
    }
}

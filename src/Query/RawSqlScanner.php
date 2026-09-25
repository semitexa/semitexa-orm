<?php

declare(strict_types=1);

namespace Semitexa\Orm\Query;

/**
 * Lexical helpers for caller-supplied raw SQL fragments: finds `?`
 * placeholders while skipping quoted strings, quoted identifiers and
 * comments, so a literal `'?'` is never mistaken for a binding.
 *
 * @internal Shared by ResourceModelQuery and WhereTrait.
 */
final class RawSqlScanner
{
    /**
     * Byte offsets of '?' placeholders that sit outside quoted regions and
     * SQL comments.
     *
     * @return list<int>
     */
    public static function placeholderOffsets(string $sql): array
    {
        $offsets = [];
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            if (self::advancePastQuotedOrCommentRegion($sql, $i, $length)) {
                continue;
            }

            if ($sql[$i] === '?') {
                $offsets[] = $i;
            }
            $i++;
        }

        return $offsets;
    }

    public static function advancePastQuotedOrCommentRegion(string $sql, int &$offset, int $length): bool
    {
        $ch = $sql[$offset];

        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $quote = $ch;
            $offset++;
            while ($offset < $length) {
                if ($sql[$offset] === '\\' && $quote !== '`' && $offset + 1 < $length) {
                    // Backslash escape (MySQL default for string literals).
                    $offset += 2;
                    continue;
                }
                if ($sql[$offset] === $quote) {
                    if ($offset + 1 < $length && $sql[$offset + 1] === $quote) {
                        // Doubled quote — SQL-standard escape.
                        $offset += 2;
                        continue;
                    }
                    $offset++;
                    break;
                }
                $offset++;
            }
            return true;
        }

        if ($ch === '-' && $offset + 1 < $length && $sql[$offset + 1] === '-') {
            $offset += 2;
            while ($offset < $length && $sql[$offset] !== "\n" && $sql[$offset] !== "\r") {
                $offset++;
            }
            return true;
        }

        if ($ch === '#') {
            $offset++;
            while ($offset < $length && $sql[$offset] !== "\n" && $sql[$offset] !== "\r") {
                $offset++;
            }
            return true;
        }

        if ($ch === '/' && $offset + 1 < $length && $sql[$offset + 1] === '*') {
            $commentEnd = strpos($sql, '*/', $offset + 2);
            $offset = $commentEnd === false ? $length : $commentEnd + 2;
            return true;
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

/**
 * Native prepared statements (ATTR_EMULATE_PREPARES=false) reject a named
 * placeholder that is bound once but used several times in the SQL —
 * SQLSTATE[HY093] "Invalid parameter number". Emulated prepares silently
 * accept it, so the pattern reads naturally and keeps slipping in (it has
 * shipped twice: scheduler polling, media claimNext).
 *
 * This expander rewrites the 2nd..Nth occurrence of every repeated placeholder
 * that is actually bound (":now" → ":now__r2", ":now__r3", …) and duplicates
 * the bindings to match. The rewrite is deterministic, so the adapter's
 * per-connection statement cache keys stay stable.
 *
 * Placeholders inside single/double-quoted strings, backtick identifiers, and
 * SQL comments are never touched. Placeholders that are repeated but NOT bound
 * are left alone — PDO's own error for the missing binding stays intact.
 */
final class RepeatedPlaceholderExpander
{
    /**
     * Quoted regions and comments are consumed by the leading alternatives so
     * the placeholder branch only fires in live SQL.
     */
    private const TOKEN_PATTERN = <<<'REGEX'
    /'(?:[^'\\]|\\.|'')*'|"(?:[^"\\]|\\.|"")*"|`(?:[^`]|``)*`|--[^\n]*|#[^\n]*|\/\*.*?\*\/|:([a-zA-Z_][a-zA-Z0-9_]*)/s
    REGEX;

    /**
     * @param array<int|string, mixed> $params
     * @return array{string, array<int|string, mixed>}
     */
    public static function expand(string $sql, array $params): array
    {
        // Fast paths: nothing bound, positional binding, or no colon at all.
        if ($params === [] || array_is_list($params) || !str_contains($sql, ':')) {
            return [$sql, $params];
        }

        if (preg_match_all(self::TOKEN_PATTERN, $sql, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [$sql, $params];
        }

        $seen = [];
        $rewrites = [];
        foreach ($matches[1] as $i => [$name, $offset]) {
            if ($offset === -1) {
                continue; // quoted string / comment alternative matched
            }
            $seen[$name] = ($seen[$name] ?? 0) + 1;
            if ($seen[$name] > 1 && self::isBound($name, $params)) {
                // Full match [0][$i] includes the leading colon.
                $rewrites[] = [$matches[0][$i][1], $name, $seen[$name]];
            }
        }

        if ($rewrites === []) {
            return [$sql, $params];
        }

        // Replace back-to-front so earlier offsets stay valid.
        foreach (array_reverse($rewrites) as [$offset, $name, $occurrence]) {
            $alias = self::alias($name, $occurrence, $params);
            $sql = substr_replace($sql, ':' . $alias, $offset, strlen($name) + 1);
            [$key, $value] = self::binding($name, $params);
            $params[str_starts_with((string) $key, ':') ? ':' . $alias : $alias] = $value;
        }

        return [$sql, $params];
    }

    /**
     * @param array<int|string, mixed> $params
     */
    private static function isBound(string $name, array $params): bool
    {
        return array_key_exists($name, $params) || array_key_exists(':' . $name, $params);
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array{int|string, mixed}
     */
    private static function binding(string $name, array $params): array
    {
        return array_key_exists($name, $params)
            ? [$name, $params[$name]]
            : [':' . $name, $params[':' . $name]];
    }

    /**
     * @param array<int|string, mixed> $params
     */
    private static function alias(string $name, int $occurrence, array $params): string
    {
        $alias = "{$name}__r{$occurrence}";
        while (array_key_exists($alias, $params) || array_key_exists(':' . $alias, $params)) {
            $alias .= '_';
        }

        return $alias;
    }
}

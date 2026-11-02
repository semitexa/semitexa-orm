<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service\Schema;

/**
 * What a class's own attributes say, read from its source when the class
 * itself cannot be loaded (tk-discovery-reports-dropped-classes): whether it
 * declares #[FromTable], and which #[Connection] it names. Only the attributes
 * directly before that class's declaration count, so a docblock, comment or
 * string that mentions FromTable is not read as a table.
 */
final class DeclaredClassAttributes
{
    private function __construct(
        public readonly bool $fromTable,
        public readonly string $connection,
    ) {}

    /** The class's declared attributes, or null when its source cannot be read or names no such class. */
    public static function of(string $source, string $className): ?self
    {
        $short = substr($className, (int) strrpos('\\' . $className, '\\'));
        $tokens = token_get_all($source);
        $attributes = '';
        $depth = 0;
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $id = is_array($token) ? $token[0] : null;
            $text = is_array($token) ? $token[1] : $token;
            if ($depth > 0) {
                // Inside #[ ... ]: collect it, tracking nested brackets.
                $depth += substr_count($text, '[') - substr_count($text, ']');
                $attributes .= $text;
                continue;
            }
            if ($id === T_ATTRIBUTE) {
                $depth = 1;
                $attributes .= $text;
                continue;
            }
            if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_FINAL, T_ABSTRACT, T_READONLY], true)) {
                continue;
            }
            if ($id === T_CLASS) {
                $name = self::nextName($tokens, $i + 1);
                if ($name === $short) {
                    return new self(
                        preg_match('/(?:^|[\\\\\s\[,])FromTable\s*[(\],]/', $attributes) === 1,
                        preg_match('/(?:^|[\\\\\s\[,])Connection\s*\(\s*(?:name\s*:\s*)?[\'"]([^\'"]+)[\'"]/', $attributes, $m) === 1 ? $m[1] : 'default',
                    );
                }
            }
            // Anything else ends the run of attributes before a declaration.
            $attributes = '';
        }

        return null;
    }

    /** @param list<mixed> $tokens */
    private static function nextName(array $tokens, int $from): ?string
    {
        for ($j = $from, $n = count($tokens); $j < $n; $j++) {
            $token = $tokens[$j];
            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }

            return is_array($token) && $token[0] === T_STRING ? $token[1] : null;
        }

        return null;
    }
}

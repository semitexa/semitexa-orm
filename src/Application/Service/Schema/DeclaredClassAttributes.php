<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service\Schema;

use Semitexa\Orm\Attribute\Connection;
use Semitexa\Orm\Attribute\FromTable;

/**
 * What a class's own attributes say, read from its source when the class
 * itself cannot be loaded (tk-discovery-reports-dropped-classes): whether it
 * declares #[FromTable], and which #[Connection] it names.
 *
 * Read by PHP's tokens, the way the engine reads them:
 * - only the attributes directly before the declaration of exactly that
 *   class (its namespace included) count;
 * - an attribute's name is resolved through the file's `use` imports, so
 *   `use …\Connection as Db; #[Db('analytics')]` is a Connection;
 * - brackets and names inside string arguments are string contents, never
 *   syntax.
 */
final class DeclaredClassAttributes
{
    /** The tokens a class or attribute name can be. */
    private const NAMES = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];

    private function __construct(
        public readonly bool $fromTable,
        public readonly string $connection,
    ) {}

    /** The class's declared attributes, or null when the source declares no such class. */
    public static function of(string $source, string $className): ?self
    {
        $wanted = ltrim($className, '\\');
        $tokens = self::tokens($source);
        $namespace = '';
        /** @var array<string, string> $imports */
        $imports = [];
        /** @var list<array{name: string, firstString: ?string}> $attributes */
        $attributes = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $id = $tokens[$i][0];
            if ($id === T_NAMESPACE) {
                $namespace = self::nameAt($tokens, $i + 1);
                $imports = [];
                $attributes = [];
                continue;
            }
            if ($id === T_USE && $attributes === []) {
                $i = self::imports($tokens, $i + 1, $imports);
                continue;
            }
            if ($id === T_ATTRIBUTE) {
                $i = self::attributeGroup($tokens, $i + 1, $attributes);
                continue;
            }
            if (in_array($id, [T_FINAL, T_ABSTRACT, T_READONLY], true)) {
                continue;
            }
            if ($id === T_CLASS) {
                $short = self::nameAt($tokens, $i + 1);
                if (ltrim(($namespace === '' ? '' : $namespace . '\\') . $short, '\\') === $wanted) {
                    $fromTable = false;
                    $connection = 'default';
                    foreach ($attributes as $attribute) {
                        $resolved = self::resolve($attribute['name'], $namespace, $imports);
                        $fromTable = $fromTable || $resolved === FromTable::class;
                        if ($resolved === Connection::class && $attribute['firstString'] !== null) {
                            $connection = $attribute['firstString'];
                        }
                    }

                    return new self($fromTable, $connection);
                }
            }
            // Anything else ends the run of attributes before a declaration.
            $attributes = [];
        }

        return null;
    }

    /**
     * The source's tokens as [id, text], whitespace and comments left out;
     * a one-character token's id is the character itself.
     *
     * @return list<array{0: int|string, 1: string}>
     */
    private static function tokens(string $source): array
    {
        $out = [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (!in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $out[] = [$token[0], $token[1]];
                }
            } else {
                $out[] = [$token, $token];
            }
        }

        return $out;
    }

    /** @param list<array{0: int|string, 1: string}> $tokens */
    private static function nameAt(array $tokens, int $at): string
    {
        $token = $tokens[$at] ?? null;

        return $token !== null && in_array($token[0], self::NAMES, true) ? $token[1] : '';
    }

    /**
     * `use A\B;`, `use A\B as C;`, `use A\{B, C as D};` into lower-cased
     * short name => FQCN. `use function` and `use const` import no class.
     *
     * @param list<array{0: int|string, 1: string}> $tokens
     * @param array<string, string> $imports
     */
    private static function imports(array $tokens, int $at, array &$imports): int
    {
        $prefix = '';
        $current = '';
        $alias = null;
        $n = count($tokens);
        for (; $at < $n; $at++) {
            [$id, $text] = $tokens[$at];
            if ($id === T_FUNCTION || $id === T_CONST) {
                while ($at < $n && $tokens[$at][0] !== ';') {
                    $at++;
                }

                return $at;
            }
            if ($id === ';' || $id === ',' || $id === '}') {
                self::import($imports, $prefix . $current, $alias);
                [$current, $alias] = ['', null];
                if ($id === ';') {
                    return $at;
                }
            } elseif ($id === '{') {
                [$prefix, $current] = [$current, ''];
            } elseif ($id === T_AS) {
                $alias = '';
            } elseif ($id === T_NS_SEPARATOR) {
                $current .= '\\';
            } elseif (in_array($id, self::NAMES, true)) {
                if ($alias === '') {
                    $alias = $text;
                } else {
                    $current .= $text;
                }
            }
        }

        return $at;
    }

    /** @param array<string, string> $imports */
    private static function import(array &$imports, string $name, ?string $alias): void
    {
        $full = ltrim($name, '\\');
        if ($full === '') {
            return;
        }
        $short = $alias !== null && $alias !== '' ? $alias : substr($full, (int) strrpos('\\' . $full, '\\'));
        $imports[strtolower($short)] = $full;
    }

    /**
     * One `#[ … ]` group: each attribute's name and its first string argument.
     * Depth counts the bracket tokens only, so a `[` in a string is text.
     *
     * @param list<array{0: int|string, 1: string}> $tokens
     * @param list<array{name: string, firstString: ?string}> $attributes
     */
    private static function attributeGroup(array $tokens, int $at, array &$attributes): int
    {
        $depth = 1;
        $parens = 0;
        $expectName = true;
        $n = count($tokens);
        for (; $at < $n; $at++) {
            [$id, $text] = $tokens[$at];
            if ($id === '[') {
                $depth++;
            } elseif ($id === ']') {
                if (--$depth === 0) {
                    break;
                }
            } elseif ($id === '(') {
                $parens++;
            } elseif ($id === ')') {
                $parens--;
            } elseif ($id === ',' && $depth === 1 && $parens === 0) {
                $expectName = true;
            } elseif ($expectName && in_array($id, self::NAMES, true)) {
                $attributes[] = ['name' => $text, 'firstString' => null];
                $expectName = false;
            } elseif ($id === T_CONSTANT_ENCAPSED_STRING && $parens > 0 && $attributes !== []) {
                $last = count($attributes) - 1;
                $attributes[$last]['firstString'] ??= stripcslashes(substr($text, 1, -1));
            }
        }

        return $at;
    }

    /** @param array<string, string> $imports */
    private static function resolve(string $name, string $namespace, array $imports): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }
        $parts = explode('\\', $name);
        $first = strtolower($parts[0]);
        if (isset($imports[$first])) {
            return $imports[$first] . substr($name, strlen($parts[0]));
        }

        return ltrim(($namespace === '' ? '' : $namespace . '\\') . $name, '\\');
    }
}

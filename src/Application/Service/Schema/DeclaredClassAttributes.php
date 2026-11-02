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
        /** The #[Connection] it names; null when its argument is not one string literal (unknown). */
        public readonly ?string $connection,
    ) {}

    /** The class's declared attributes, or null when the source declares no such class. */
    public static function of(string $source, string $className): ?self
    {
        $wanted = ltrim($className, '\\');
        $tokens = self::tokens($source);
        $namespace = '';
        /** @var array<string, string> $imports */
        $imports = [];
        /** @var list<array{name: string, literal: ?string, args: bool}> $attributes */
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
            if ($id === T_USE && $attributes === [] && ($tokens[$i + 1][0] ?? null) !== '(') {
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
            $previous = $tokens[$i - 1][0] ?? null;
            if ($id === T_CLASS && $previous === T_NEW) {
                // `new class { use T; }`: its body holds no import either.
                $i = self::skipBody($tokens, $i);
                $attributes = [];
                continue;
            }
            $declares = in_array($id, [T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM], true) && $previous !== T_DOUBLE_COLON;
            if ($declares) {
                $short = self::nameAt($tokens, $i + 1);
                if ($id === T_CLASS && strtolower(ltrim(($namespace === '' ? '' : $namespace . '\\') . $short, '\\')) === strtolower($wanted)) {
                    $fromTable = false;
                    $connection = 'default';
                    foreach ($attributes as $attribute) {
                        $resolved = strtolower(self::resolve($attribute['name'], $namespace, $imports));
                        $fromTable = $fromTable || $resolved === strtolower(FromTable::class);
                        if ($resolved === strtolower(Connection::class)) {
                            // Not one string literal (a constant, a concatenation): unknown, never assumed.
                            // #[Connection] / #[Connection()] is the attribute's own default.
                            $connection = !$attribute['args'] || $attribute['literal'] === '' ? 'default' : $attribute['literal'];
                        }
                    }

                    return new self($fromTable, $connection);
                }
                // Another declaration: its body holds trait `use`s and closures, not imports.
                $i = self::skipBody($tokens, $i);
                $attributes = [];
                continue;
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
        $inGroup = false;
        $skipMember = false;
        $n = count($tokens);
        for (; $at < $n; $at++) {
            [$id, $text] = $tokens[$at];
            if ($id === T_FUNCTION || $id === T_CONST) {
                if ($inGroup) {
                    // `use A\{function f, B}`: only this member imports no class.
                    $skipMember = true;
                    continue;
                }
                while ($at < $n && $tokens[$at][0] !== ';') {
                    $at++;
                }

                return $at;
            }
            if ($id === ';' || $id === ',' || $id === '}') {
                if (!$skipMember) {
                    self::import($imports, $prefix . $current, $alias);
                }
                [$current, $alias, $skipMember] = ['', null, false];
                if ($id === ';') {
                    return $at;
                }
            } elseif ($id === '{') {
                [$prefix, $current, $inGroup] = [$current, '', true];
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
     * One `#[ … ]` group: each attribute's name, and its argument when that is
     * exactly one string literal (positional or `name:`). Depth counts the
     * bracket tokens only, so a `[` in a string is text.
     *
     * @param list<array{0: int|string, 1: string}> $tokens
     * @param list<array{name: string, literal: ?string, args: bool}> $attributes
     */
    private static function attributeGroup(array $tokens, int $at, array &$attributes): int
    {
        $depth = 1;
        $parens = 0;
        $expectName = true;
        /** @var list<array{0: int|string, 1: string}> $args */
        $args = [];
        $n = count($tokens);
        for (; $at < $n; $at++) {
            [$id, $text] = $tokens[$at];
            if ($parens > 0 && !($parens === 1 && $id === ')')) {
                $args[] = $tokens[$at];
            }
            if ($id === '[') {
                $depth++;
            } elseif ($id === ']') {
                if (--$depth === 0) {
                    break;
                }
            } elseif ($id === '(') {
                $parens++;
            } elseif ($id === ')') {
                if (--$parens === 0 && $attributes !== []) {
                    $last = count($attributes) - 1;
                    $attributes[$last]['literal'] = self::literal($args);
                    $attributes[$last]['args'] = true;
                    $args = [];
                }
            } elseif ($id === ',' && $depth === 1 && $parens === 0) {
                $expectName = true;
            } elseif ($expectName && $parens === 0 && in_array($id, self::NAMES, true)) {
                $attributes[] = ['name' => $text, 'literal' => null, 'args' => false];
                $expectName = false;
            }
        }

        return $at;
    }

    /**
     * The value of an argument list that is one string literal, `'x'` or
     * `name: 'x'`; null for anything else.
     *
     * @param list<array{0: int|string, 1: string}> $args
     */
    private static function literal(array $args): ?string
    {
        if ($args === []) {
            return '';
        }
        // `name:` is the attribute's only parameter; any other name is no literal of it.
        if (count($args) === 3 && $args[0][0] === T_STRING && $args[1][0] === ':') {
            if ($args[0][1] !== 'name') { // named arguments match case-sensitively
                return null;
            }
            $args = [$args[2]];
        }
        if (count($args) !== 1 || $args[0][0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        $raw = $args[0][1];
        $body = substr($raw, 1, -1);

        // Single quotes keep every escape but \\ and \' literal; double quotes decode them.
        return $raw[0] === "'" ? str_replace(['\\\\', "\\'"], ['\\', "'"], $body) : stripcslashes($body);
    }

    /**
     * The index of the `}` that closes the body of the declaration at $at.
     *
     * @param list<array{0: int|string, 1: string}> $tokens
     */
    private static function skipBody(array $tokens, int $at): int
    {
        $n = count($tokens);
        while ($at < $n && $tokens[$at][0] !== '{') {
            $at++;
        }
        $depth = 0;
        for (; $at < $n; $at++) {
            $id = $tokens[$at][0];
            if ($id === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            } elseif ($id === '}' && --$depth === 0) {
                return $at;
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
        if (str_starts_with(strtolower($name), 'namespace\\')) {
            return ltrim(($namespace === '' ? '' : $namespace . '\\') . substr($name, strlen('namespace\\')), '\\');
        }
        $parts = explode('\\', $name);
        $first = strtolower($parts[0]);
        if (isset($imports[$first])) {
            return $imports[$first] . substr($name, strlen($parts[0]));
        }

        return ltrim(($namespace === '' ? '' : $namespace . '\\') . $name, '\\');
    }
}

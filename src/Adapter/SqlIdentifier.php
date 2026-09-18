<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

/**
 * The one place that turns a table or column name into SQL.
 *
 * Wrapping a name in its quote character is not escaping it. A backtick inside
 * the name closes the quote, and everything after it is parsed as SQL — how
 * `name` . ', (SELECT 1) AS x -- ' turns a GROUP BY into a subquery. The rule
 * MySQL actually specifies is to double the backtick, and SQLite accepts the
 * same form.
 *
 * This existed already, correctly, as five private copies — in InsertQuery,
 * UpdateQuery, WhereTrait (twice) and DeleteQuery — and was absent from the
 * thirty-odd other places that interpolate an identifier. UpdateQuery's copy
 * carried the reason in a docblock: "identifiers here are metadata-derived
 * today, but the builder must not trust its caller for that." The copies are
 * gone; this is what they said.
 *
 * Escaping rather than validating is deliberate. An allowlist pattern has to
 * guess which identifiers a consumer's schema is allowed to contain, and it is
 * wrong the first time somebody has a column with a space in it. Doubling the
 * backtick is correct for every legal identifier, and turns a hostile string
 * into a column name the database rejects — an error, not an injection.
 */
final class SqlIdentifier
{
    /** MySQL's identifier quote, and the one SQLite also accepts. */
    public const BACKTICK = '`';

    /** The ANSI form, which SyncEngine emits on the SQLite path. */
    public const DOUBLE_QUOTE = '"';

    /**
     * $quoteChar is DOCUMENTED as a plain string and CHECKED at runtime, which
     * is not a weaker contract than the literal union it used to declare — it
     * is the only one that is true. The union told the analyser that no other
     * value could arrive, so the guard below became unreachable code
     * (`notIdentical.alwaysFalse`), every internal caller that forwards a
     * `string $quoteChar` became an `argument.type` error, and the negative
     * test that proves a single quote is refused could not be written at all.
     * The values this accepts are {@see self::BACKTICK} and
     * {@see self::DOUBLE_QUOTE}; anything else is an exception with a reason.
     *
     * @throws \InvalidArgumentException when the name cannot be a SQL identifier at all,
     *                                   or the quote character is not one of the two
     */
    public static function quote(string $identifier, string $quoteChar = self::BACKTICK): string
    {
        if ($quoteChar !== self::BACKTICK && $quoteChar !== self::DOUBLE_QUOTE) {
            throw new \InvalidArgumentException(
                'Identifier quote must be ` or ", not ' . $quoteChar . '. A single quote delimits a '
                . 'string literal, not an identifier, and doubling it here would hide that mistake.',
            );
        }

        if ($identifier === '') {
            throw new \InvalidArgumentException('SQL identifiers must not be empty.');
        }

        // Neither dialect can represent U+0000 inside a quoted identifier at
        // all, so there is no escape to apply — it can only be refused.
        if (str_contains($identifier, "\0")) {
            throw new \InvalidArgumentException('SQL identifiers must not contain a null byte.');
        }

        return $quoteChar . str_replace($quoteChar, $quoteChar . $quoteChar, $identifier) . $quoteChar;
    }

    /**
     * A possibly-qualified reference: `orders`.`total`, or just `total`.
     *
     * Splits on the FIRST dot only, matching WhereTrait's long-standing
     * behaviour — a name whose column part contains a dot stays one identifier
     * rather than becoming a three-part reference nobody asked for.
     */
    public static function quoteQualified(string $reference, string $quoteChar = self::BACKTICK): string
    {
        if (!str_contains($reference, '.')) {
            return self::quote($reference, $quoteChar);
        }

        return implode('.', array_map(
            static fn (string $part): string => self::quote($part, $quoteChar),
            explode('.', $reference, 2),
        ));
    }

    /**
     * Every identifier quoted, KEYS PRESERVED.
     *
     * array_map() over a single array keeps its keys, so
     * `['id' => 'user_id']` comes back as `['id' => '`user_id`']` — which is
     * what the callers building a column map rely on. The old `list<string>`
     * described neither the input those callers pass nor the shape they get
     * back, and made a legitimate associative call an analyser error.
     *
     * @param array<array-key, string> $identifiers
     * @return array<array-key, string>
     */
    public static function quoteAll(array $identifiers, string $quoteChar = self::BACKTICK): array
    {
        return array_map(
            static fn (string $identifier): string => self::quote($identifier, $quoteChar),
            $identifiers,
        );
    }
}

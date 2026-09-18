<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\SqlIdentifier;

final class SqlIdentifierTest extends TestCase
{
    #[Test]
    public function an_ordinary_name_is_wrapped(): void
    {
        self::assertSame('`total`', SqlIdentifier::quote('total'));
    }

    /**
     * The whole reason this class exists. Each payload is one that escaped the
     * backticks before the quoting was made universal; the assertion is that
     * the closing backtick is now doubled, so nothing after it is parsed as SQL.
     *
     * @param string $payload a hostile "column name"
     */
    #[Test]
    #[DataProvider('breakoutAttempts')]
    public function a_backtick_cannot_close_the_quote(string $payload): void
    {
        $quoted = SqlIdentifier::quote($payload);

        self::assertStringStartsWith('`', $quoted);
        self::assertStringEndsWith('`', $quoted);

        // Strip the wrapping pair, then every remaining backtick must be part
        // of a doubled pair — an odd run would mean one of them still closes.
        $inner = substr($quoted, 1, -1);
        foreach (self::backtickRunLengths($inner) as $run) {
            self::assertSame(0, $run % 2, "a run of {$run} backticks survives in: {$quoted}");
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function breakoutAttempts(): iterable
    {
        yield 'the proven countBy() payload' => ['name` , (SELECT 1) AS x -- '];
        yield 'closing and reopening' => ['a`, `b'];
        yield 'a lone backtick' => ['`'];
        yield 'two backticks' => ['``'];
        yield 'three backticks' => ['```'];
        yield 'trailing backtick' => ['col`'];
        yield 'union attempt' => ['x` FROM users UNION SELECT password FROM users -- '];
    }

    #[Test]
    public function an_already_doubled_backtick_is_doubled_again_so_it_round_trips(): void
    {
        // MySQL reads `a``b` as the identifier a`b. Quoting the literal name
        // a``b must therefore produce four backticks, not leave two.
        self::assertSame('`a````b`', SqlIdentifier::quote('a``b'));
    }

    #[Test]
    public function an_empty_name_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SqlIdentifier::quote('');
    }

    #[Test]
    public function a_null_byte_is_refused_because_there_is_no_escape_for_it(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SqlIdentifier::quote("col\0umn");
    }

    #[Test]
    public function a_qualified_reference_quotes_both_halves(): void
    {
        self::assertSame('`orders`.`total`', SqlIdentifier::quoteQualified('orders.total'));
    }

    #[Test]
    public function a_qualified_reference_splits_on_the_first_dot_only(): void
    {
        self::assertSame('`a`.`b.c`', SqlIdentifier::quoteQualified('a.b.c'));
    }

    #[Test]
    public function an_unqualified_reference_is_left_as_one_identifier(): void
    {
        self::assertSame('`total`', SqlIdentifier::quoteQualified('total'));
    }

    #[Test]
    public function a_qualified_reference_cannot_be_used_to_smuggle_a_backtick(): void
    {
        self::assertSame('`a``b`.`c``d`', SqlIdentifier::quoteQualified('a`b.c`d'));
    }

    #[Test]
    public function quote_all_maps_the_list(): void
    {
        self::assertSame(['`a`', '`b`'], SqlIdentifier::quoteAll(['a', 'b']));
    }

    #[Test]
    public function the_ansi_quote_doubles_its_own_character(): void
    {
        // SyncEngine emits this form on the SQLite path. Doubling the BACKTICK
        // there would leave the double quote free to close the identifier.
        self::assertSame('"a""b"', SqlIdentifier::quote('a"b', SqlIdentifier::DOUBLE_QUOTE));
    }

    #[Test]
    public function the_ansi_quote_leaves_a_backtick_alone(): void
    {
        self::assertSame('"a`b"', SqlIdentifier::quote('a`b', SqlIdentifier::DOUBLE_QUOTE));
    }

    #[Test]
    public function the_dialect_reaches_the_qualified_and_list_forms_too(): void
    {
        self::assertSame('"a"."b"', SqlIdentifier::quoteQualified('a.b', SqlIdentifier::DOUBLE_QUOTE));
        self::assertSame(['"a"'], SqlIdentifier::quoteAll(['a'], SqlIdentifier::DOUBLE_QUOTE));
    }

    /**
     * A single quote delimits a STRING in both dialects. Accepting it here
     * would turn "escape this identifier" into "build a literal", and the
     * doubling would look like it had worked.
     */
    #[Test]
    public function a_string_delimiter_is_refused_as_an_identifier_quote(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SqlIdentifier::quote('col', "'");
    }

    /**
     * @return list<int>
     */
    private static function backtickRunLengths(string $subject): array
    {
        preg_match_all('/`+/', $subject, $matches);

        return array_map(strlen(...), $matches[0]);
    }
}

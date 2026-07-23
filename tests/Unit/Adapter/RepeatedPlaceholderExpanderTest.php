<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\RepeatedPlaceholderExpander;

/**
 * Regression guard for the HY093 class of bugs: a named placeholder bound once
 * but used repeatedly is rejected by native prepared statements
 * (ATTR_EMULATE_PREPARES=false). Shipped twice before the adapter-level fix:
 * scheduler polling and media claimNext.
 */
final class RepeatedPlaceholderExpanderTest extends TestCase
{
    #[Test]
    public function expandsRepeatedPlaceholderIntoUniqueOnesWithMatchingBindings(): void
    {
        [$sql, $params] = RepeatedPlaceholderExpander::expand(
            'UPDATE t SET a = :now, b = :now WHERE c < :now',
            ['now' => '2026-07-22 00:00:00'],
        );

        self::assertSame('UPDATE t SET a = :now, b = :now__r2 WHERE c < :now__r3', $sql);
        self::assertSame(
            [
                'now' => '2026-07-22 00:00:00',
                'now__r3' => '2026-07-22 00:00:00',
                'now__r2' => '2026-07-22 00:00:00',
            ],
            $params,
        );
    }

    #[Test]
    public function expandsTheClaimNextShapedStatement(): void
    {
        $sql = "UPDATE media_variants
             SET status = 'processing',
                 last_attempt_at = :now,
                 processing_started_at = CASE WHEN processing_started_at IS NULL THEN :now ELSE processing_started_at END
             WHERE (status = 'queued' OR (status = 'processing' AND lease_expires_at < :now))
               AND lease_owner = :lease_owner";

        [$expanded, $params] = RepeatedPlaceholderExpander::expand($sql, [
            'now' => 'X',
            'lease_owner' => 'w1',
        ]);

        self::assertSame(1, preg_match_all('/:now\b/', $expanded), 'first occurrence unchanged');
        self::assertStringContainsString(':now__r2', $expanded);
        self::assertStringContainsString(':now__r3', $expanded);
        self::assertCount(4, $params);
        self::assertSame('X', $params['now__r2']);
        self::assertSame('X', $params['now__r3']);
        self::assertSame('w1', $params['lease_owner']);
    }

    #[Test]
    public function leavesSingleUsePlaceholdersAlone(): void
    {
        $sql = 'SELECT * FROM t WHERE a = :a AND b = :b';
        $params = ['a' => 1, 'b' => 2];

        self::assertSame([$sql, $params], RepeatedPlaceholderExpander::expand($sql, $params));
    }

    #[Test]
    public function leavesPositionalAndEmptyBindingsAlone(): void
    {
        $sql = 'SELECT * FROM t WHERE a = ? AND b = ?';

        self::assertSame([$sql, [1, 2]], RepeatedPlaceholderExpander::expand($sql, [1, 2]));
        self::assertSame(['SELECT 1', []], RepeatedPlaceholderExpander::expand('SELECT 1', []));
    }

    #[Test]
    public function leavesRepeatedButUnboundPlaceholdersForPdoToReject(): void
    {
        $sql = 'UPDATE t SET a = :now, b = :now WHERE id = :id';
        $params = ['id' => 7];

        self::assertSame([$sql, $params], RepeatedPlaceholderExpander::expand($sql, $params));
    }

    #[Test]
    public function ignoresPlaceholderLookalikesInStringsCommentsAndIdentifiers(): void
    {
        $sql = "SELECT ':now', \":now\", `col:now`, x -- :now\n FROM t /* :now */ WHERE a = :now AND b = :now # :now";

        [$expanded, $params] = RepeatedPlaceholderExpander::expand($sql, ['now' => 'X']);

        self::assertStringContainsString("':now'", $expanded);
        self::assertStringContainsString('":now"', $expanded);
        self::assertStringContainsString('`col:now`', $expanded);
        self::assertStringContainsString('-- :now', $expanded);
        self::assertStringContainsString('/* :now */', $expanded);
        self::assertStringContainsString('# :now', $expanded);
        self::assertStringContainsString('b = :now__r2', $expanded);
        self::assertSame(['now' => 'X', 'now__r2' => 'X'], $params);
    }

    #[Test]
    public function respectsColonPrefixedBindingKeys(): void
    {
        [$sql, $params] = RepeatedPlaceholderExpander::expand(
            'SELECT :now AS a, :now AS b',
            [':now' => 'X'],
        );

        self::assertSame('SELECT :now AS a, :now__r2 AS b', $sql);
        self::assertSame([':now' => 'X', ':now__r2' => 'X'], $params);
    }

    #[Test]
    public function avoidsAliasCollisionsWithExistingBindings(): void
    {
        [$sql, $params] = RepeatedPlaceholderExpander::expand(
            'SELECT :now AS a, :now AS b, :now__r2 AS c',
            ['now' => 'X', 'now__r2' => 'other'],
        );

        self::assertSame('SELECT :now AS a, :now__r2_ AS b, :now__r2 AS c', $sql);
        self::assertSame('X', $params['now__r2_']);
        self::assertSame('other', $params['now__r2']);
    }

    #[Test]
    public function expansionIsDeterministicForStatementCacheStability(): void
    {
        $sql = 'UPDATE t SET a = :v, b = :v';
        $params = ['v' => 1];

        self::assertSame(
            RepeatedPlaceholderExpander::expand($sql, $params),
            RepeatedPlaceholderExpander::expand($sql, $params),
        );
    }
}

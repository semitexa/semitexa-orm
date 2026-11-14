<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Analysis;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The dialect is asked of SqlDialect, not guessed from the adapter's class or a driver
 * string (tk-orm-dialect-layer). PostgreSQL is planned, and every such guess is a place
 * it would have to patch; `instanceof SqliteAdapter` is also blind inside a transaction,
 * where the adapter is a SingleConnectionAdapter.
 *
 * A guess is a driver name written as a string (pgsql too), an adapter's class written as a
 * string, an instanceof of a Sqlite/Mysql adapter, or that adapter's ::class,
 * counted by PHP's tokenizer, so a comment never counts. The places that still guess are
 * listed with how many guesses each holds; one guess swapped for another in the same file
 * keeps the number, which the review of each step is there to see. Each PR that
 * moves one onto the dialect lowers its number, and the test refuses a number that is
 * higher than the code (left behind) or lower (a new guess). src/Adapter is where the
 * answer is given (SqlDialect and the adapters), and ConnectionConfig reads the configured driver.
 */
final class NoDialectSniffingTest extends TestCase
{

    /** @var array<string, int> */
    private const STILL_GUESSING = [
        'Application/Console/Command/OrmStatusCommand.php' => 1,
        'Application/Service/Queue/DatabaseQueueTransport.php' => 1,
        'Application/Service/Schema/SchemaCollector.php' => 5,
        'Application/Service/Sync/SchemaReset.php' => 2,
        'Application/Service/Sync/SchemaSyncLock.php' => 1,
        'Application/Service/Sync/SmartUpsert.php' => 1,
        'Application/Service/Sync/SyncEngine.php' => 1,
        'Application/Service/Transaction/SharedTestTransaction.php' => 6,
        'Application/Service/Transaction/TransactionManager.php' => 5,
        'Application/Service/WarmConnectionPoolListener.php' => 1,
        'OrmManager.php' => 12,
        'Query/InsertQuery.php' => 2,
    ];

    #[Test]
    public function every_way_a_guess_is_written_is_counted(): void
    {
        // Review, orm#111: either quote, any comparison, a switch, in_array, str_contains; never a comment.
        $guesses = <<<'PHP'
            <?php
            if ($a instanceof SqliteAdapter) {}
            if ($a instanceof \Semitexa\Orm\Adapter\MysqlAdapter) {}
            if ($d === 'sqlite' || $d == "mysql" || 'mysql' !== $d) {}
            switch ($d) { case 'sqlite': break; }
            in_array($d, ['mysql'], true);
            str_contains($class, 'sqlite');
            if ($a instanceof /* the adapter */ SqliteAdapter) {}
            if ($a instanceof \Semitexa\Orm\Adapter\SQLiteAdapter) {}
            if ($a::class === SqliteAdapter::class || get_class($a) === \Semitexa\Orm\Adapter\MysqlAdapter::class) {}
            is_a($a, 'Semitexa\Orm\Adapter\SqliteAdapter');
            if ($driver === 'pgsql') {}
            PHP;
        self::assertSame(14, self::guesses($guesses), 'comments, case, ::class, an adapter class as a string, PostgreSQL (review, orm#111)');
        self::assertSame(0, self::guesses("<?php\n// 'sqlite' in a comment\n/** 'mysql' in a docblock */\n\$x = 'sqlite3'; \$y = 'SqliteAdapterTest';\n"));
    }

    #[Test]
    public function no_new_place_guesses_the_dialect(): void
    {
        $src = dirname(__DIR__, 2) . '/src';
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($src) + 1);
            if (str_starts_with($relative, 'Adapter/') || str_ends_with($relative, 'ConnectionConfig.php')) {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source, 'cannot read ' . $file->getPathname());
            $count = self::guesses($source);
            if ($count > 0) {
                $found[$relative] = $count;
            }
        }
        ksort($found);
        $expected = self::STILL_GUESSING;
        ksort($expected);

        self::assertSame($expected, $found, 'A place guesses the dialect from an adapter class or a driver string. Ask DialectResolver::for($adapter) instead; when a PR moves a place onto it, lower its number here.');
    }

    /**
     * Each driver name written as a string ('sqlite', "mysql") and each instanceof of a
     * Sqlite/Mysql adapter, read by PHP's tokenizer: a comparison, a case, in_array or
     * str_contains alike, a comment never (review, orm#111).
     */
    private static function guesses(string $source): int
    {
        // Comments go with the whitespace: `instanceof /* note */ SqliteAdapter` is still the guess,
        // and a comment's own text never counts (review, orm#111).
        $tokens = array_values(array_filter(token_get_all($source), static fn (array|string $t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $count = 0;
        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $literal = strtolower(substr($token[1], 1, -1));
                // A driver name, PostgreSQL's included, or an adapter's class written as a string.
                if (in_array($literal, ['sqlite', 'mysql', 'pgsql', 'postgres', 'postgresql'], true) || preg_match('/(?:sqlite|mysql)adapter$/', $literal) === 1) {
                    $count++;
                }
            }
            // SqliteAdapter::class, compared to a ::class, get_class() or handed to is_a() (review, orm#111).
            if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && preg_match('/(?:^|\\\\)(?:Sqlite|Mysql)Adapter$/i', $token[1]) === 1
                && is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_DOUBLE_COLON
                && is_array($tokens[$i + 2] ?? null) && $tokens[$i + 2][0] === T_CLASS) {
                $count++;
            }
            $next = $tokens[$i + 1] ?? null;
            if ($token[0] === T_INSTANCEOF && is_array($next) && preg_match('/(?:^|\\\\)(?:Sqlite|Mysql)Adapter$/i', $next[1]) === 1) {
                $count++;
            }
        }

        return $count;
    }
}

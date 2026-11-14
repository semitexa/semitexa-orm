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
 * The places that still guess are listed with how many guesses each holds. Each PR that
 * moves one onto the dialect lowers its number, and the test refuses a number that is
 * higher than the code (left behind) or lower (a new guess). src/Adapter is where the
 * answer is given (SqlDialect and the adapters), and ConnectionConfig reads the configured driver.
 */
final class NoDialectSniffingTest extends TestCase
{
    /** Either quote: a guess written "sqlite" is the same guess (review, orm#111). */
    private const PATTERN = "/instanceof\\s+\\\\?(?:Semitexa\\\\Orm\\\\Adapter\\\\)?(?:Sqlite|Mysql)Adapter\\b|[=!]==\\s*(['\"])(?:sqlite|mysql)\\1|(['\"])(?:sqlite|mysql)\\2\\s*[=!]==/";

    /** @var array<string, int> */
    private const STILL_GUESSING = [
        'Application/Service/Queue/DatabaseQueueTransport.php' => 1,
        'Application/Service/Schema/SchemaCollector.php' => 2,
        'Application/Service/Sync/SchemaReset.php' => 2,
        'Application/Service/Sync/SchemaSyncLock.php' => 1,
        'Application/Service/Sync/SmartUpsert.php' => 1,
        'Application/Service/Sync/SyncEngine.php' => 1,
        'Application/Service/Transaction/SharedTestTransaction.php' => 3,
        'Application/Service/Transaction/TransactionAwareAdapter.php' => 1,
        'Application/Service/Transaction/TransactionManager.php' => 3,
        'Application/Service/WarmConnectionPoolListener.php' => 1,
        'OrmManager.php' => 7,
        'Query/InsertQuery.php' => 2,
    ];

    #[Test]
    public function the_pattern_sees_every_way_a_guess_is_written(): void
    {
        foreach (['$a instanceof SqliteAdapter', '$a instanceof \\Semitexa\\Orm\\Adapter\\MysqlAdapter', "\$d === 'sqlite'", '$d === "sqlite"', "'mysql' !== \$d", '"mysql" === $d'] as $guess) {
            self::assertSame(1, preg_match(self::PATTERN, $guess), $guess);
        }
        foreach (['$d === \'sqlite"', 'SqliteAdapterTest', "\$name === 'sqlite3'"] as $other) {
            self::assertSame(0, preg_match(self::PATTERN, $other), $other);
        }
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
            $count = preg_match_all(self::PATTERN, $source);
            if ($count > 0) {
                $found[$relative] = $count;
            }
        }
        ksort($found);
        $expected = self::STILL_GUESSING;
        ksort($expected);

        self::assertSame($expected, $found, 'A place guesses the dialect from an adapter class or a driver string. Ask DialectResolver::for($adapter) instead; when a PR moves a place onto it, lower its number here.');
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Dialect;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\LoggingAdapter;
use Semitexa\Orm\Application\Service\Transaction\SingleConnectionAdapter;
use Semitexa\Orm\Adapter\DialectResolver;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;

/**
 * Every adapter answers its dialect, the wrappers included (tk-orm-dialect-layer): a
 * transaction hands its work a SingleConnectionAdapter, which `instanceof SqliteAdapter`
 * never recognised.
 */
final class DialectResolverTest extends TestCase
{
    #[Test]
    public function sqlite_is_sqlite_through_every_wrapper(): void
    {
        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));

        self::assertSame('sqlite', DialectResolver::for($orm->getAdapter())->name());
        self::assertSame('sqlite', DialectResolver::for($orm->getQueryAdapter())->name(), 'the transaction-aware adapter');
        self::assertSame('sqlite', DialectResolver::for(new LoggingAdapter($orm->getAdapter()))->name(), 'the logging decorator');
        self::assertSame('sqlite', DialectResolver::for(new SingleConnectionAdapter(new \PDO('sqlite::memory:'), '3.40.0'))->name(), "a transaction's own connection");
        self::assertSame('"', DialectResolver::for($orm->getAdapter())->identifierQuote());
        self::assertFalse(DialectResolver::for($orm->getAdapter())->usesConnectionPool());
    }

    #[Test]
    public function an_adapter_that_does_not_say_is_mysql_as_before(): void
    {
        $double = $this->createStub(DatabaseAdapterInterface::class);

        self::assertSame('mysql', DialectResolver::for($double)->name(), 'the default every check fell back to');
        self::assertSame('`', DialectResolver::for($double)->identifierQuote());
        self::assertTrue(DialectResolver::for($double)->usesConnectionPool());
        self::assertSame('mysql', DialectResolver::forDriver('mysql')->name());
        self::assertSame('sqlite', DialectResolver::forDriver('sqlite')->name());
    }
}

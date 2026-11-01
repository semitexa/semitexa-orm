<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\ConnectionPoolInterface;
use Semitexa\Orm\Adapter\MysqlAdapter;
use Semitexa\Orm\Adapter\ServerCapability;

/**
 * A refused server version is refused on every call, not only the first: kept
 * before the check, a 5.7 answered later supports() calls with "no locking
 * reads", and a forUpdate() read ran without FOR UPDATE (orm#99 review).
 */
final class MysqlVersionRefusalTest extends TestCase
{
    #[Test]
    public function a_server_below_8_is_refused_every_time_it_is_asked(): void
    {
        $adapter = new MysqlAdapter(self::poolAnswering('5.7.44-log'));

        foreach (['first', 'second'] as $call) {
            try {
                $adapter->supports(ServerCapability::LockingReads);
                self::fail("the {$call} call answered for MySQL 5.7");
            } catch (\RuntimeException $e) {
                self::assertSame('Semitexa ORM requires MySQL 8.0+, got 5.7.44.', $e->getMessage(), $call);
            }
        }
    }

    #[Test]
    public function a_supported_server_is_kept(): void
    {
        $adapter = new MysqlAdapter(self::poolAnswering('8.0.40'));

        self::assertTrue($adapter->supports(ServerCapability::LockingReads));
        self::assertSame('8.0.40', $adapter->getServerVersion());
    }

    /** A connection whose SELECT VERSION() answers $version (SQLite with a VERSION() function). */
    private static function poolAnswering(string $version): ConnectionPoolInterface
    {
        $pdo = \PDO::connect('sqlite::memory:');
        self::assertInstanceOf(\Pdo\Sqlite::class, $pdo);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->createFunction('VERSION', static fn (): string => $version, 0);

        return new class ($pdo) implements ConnectionPoolInterface {
            public function __construct(private readonly \PDO $pdo) {}

            public function pop(?float $timeout = null): \PDO
            {
                return $this->pdo;
            }

            public function push(\PDO $connection): void {}

            public function close(): void {}

            public function getSize(): int
            {
                return 1;
            }

            public function getAvailable(): int
            {
                return 1;
            }

            public function switchTo(string $tenantId): void {}
        };
    }
}

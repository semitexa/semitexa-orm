<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Sync;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Orm\Application\Console\Command\OrmFreshCommand;
use Semitexa\Orm\Application\Service\Connection\ConnectionRegistry;
use Semitexa\Orm\Application\Service\Schema\SchemaCollector;
use Semitexa\Orm\OrmManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** orm:fresh deletes all data, so it refuses outside dev, local and test before it touches a connection. */
final class OrmFreshCommandTest extends TestCase
{
    private string|false $appEnv;

    protected function setUp(): void
    {
        $this->appEnv = getenv('APP_ENV');
    }

    protected function tearDown(): void
    {
        putenv($this->appEnv === false ? 'APP_ENV' : 'APP_ENV=' . $this->appEnv);
    }

    #[Test]
    public function it_refuses_outside_a_development_environment_before_touching_a_connection(): void
    {
        foreach (['prod', 'production', 'staging', ''] as $env) {
            putenv('APP_ENV=' . $env);
            // No connection registry is injected: reaching one would throw.
            $tester = new CommandTester(new OrmFreshCommand());

            self::assertSame(Command::FAILURE, $tester->execute([]), $env);
            self::assertStringContainsString('runs only with APP_ENV dev, local, test', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()), $env);
        }
    }

    #[Test]
    public function an_empty_connection_name_is_refused_before_anything_is_dropped(): void
    {
        putenv('APP_ENV=test');
        $tester = new CommandTester(new OrmFreshCommand());

        self::assertSame(Command::FAILURE, $tester->execute(['--connection' => '   ']));
        self::assertStringContainsString('must not be empty', $tester->getDisplay());
    }

    /** orm:sync refuses a schema with errors; refused after the drop, the database was left empty. */
    #[Test]
    public function a_schema_with_errors_is_refused_before_anything_is_dropped(): void
    {
        putenv('APP_ENV=test');
        $dir = sys_get_temp_dir() . '/semitexa-fresh-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/Orders.php', "<?php\n#[FromTable(name: 'orders')]\nfinal class Orders extends Missing {}\n");
        try {
            $discovery = $this->createStub(ClassDiscovery::class);
            $discovery->method('findClassesWithAttribute')->willReturn([]);
            $discovery->method('getClassMap')->willReturn(['Orders' => $dir . '/Orders.php']);
            $discovery->method('unloadableClasses')->willReturn(['Orders' => 'load error']);
            $orm = $this->createMock(OrmManager::class);
            $orm->method('getSchemaCollector')->willReturn(new SchemaCollector(classDiscovery: $discovery, driver: 'sqlite'));
            $orm->expects(self::never())->method('getAdapter');
            $registry = new ConnectionRegistry();
            $registry->register('default', $orm);
            $command = new OrmFreshCommand();
            (new \ReflectionProperty(OrmFreshCommand::class, 'connections'))->setValue($command, $registry);

            $tester = new CommandTester($command);
            self::assertSame(Command::FAILURE, $tester->execute([]));
            self::assertStringContainsString('nothing was dropped', $tester->getDisplay());
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    #[Test]
    public function seeding_is_refused_for_a_connection_orm_seed_cannot_reach(): void
    {
        putenv('APP_ENV=test');
        $tester = new CommandTester(new OrmFreshCommand());

        self::assertSame(Command::FAILURE, $tester->execute(['--connection' => 'analytics', '--seed' => true]));
        self::assertStringContainsString('--seed works with the default connection only', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }
}

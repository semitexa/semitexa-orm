<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Sync;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Application\Console\Command\OrmFreshCommand;
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
    public function seeding_is_refused_for_a_connection_orm_seed_cannot_reach(): void
    {
        putenv('APP_ENV=test');
        $tester = new CommandTester(new OrmFreshCommand());

        self::assertSame(Command::FAILURE, $tester->execute(['--connection' => 'analytics', '--seed' => true]));
        self::assertStringContainsString('--seed works with the default connection only', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }
}

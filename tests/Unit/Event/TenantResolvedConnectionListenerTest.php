<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Orm\Adapter\ConnectionPoolInterface;
use Semitexa\Orm\Adapter\TenantSwitchingConnectionPoolInterface;
use Semitexa\Orm\Event\TenantResolvedConnectionListener;
use Semitexa\Tenancy\Context\TenantContext;
use Semitexa\Tenancy\Event\TenantResolved;

final class TenantResolvedConnectionListenerTest extends TestCase
{
    #[Test]
    public function it_ignores_unsupported_connection_switching_for_resolved_tenant(): void
    {
        $this->expectNotToPerformAssertions();

        $listener = new TenantResolvedConnectionListener();
        $this->injectPool($listener, new class implements TenantSwitchingConnectionPoolInterface {
            public function pop(float $timeout = -1): \PDO
            {
                throw new \BadMethodCallException('Not used in this test.');
            }

            public function push(\PDO $connection): void
            {
            }

            public function close(): void
            {
            }

            public function getSize(): int
            {
                return 1;
            }

            public function getAvailable(): int
            {
                return 0;
            }

            public function switchTo(string $tenantId): void
            {
                throw new \LogicException('Tenant database switching is not configured.');
            }

            public function supportsTenantSwitch(): bool
            {
                return false;
            }
        });

        $listener->handle(new TenantResolved(TenantContext::fromResolution('os', 'domain', 'os.semitexa.test')));
    }

    #[Test]
    public function it_switches_when_pool_supports_tenant_connection_switching(): void
    {
        $listener = new TenantResolvedConnectionListener();
        $pool = new class implements TenantSwitchingConnectionPoolInterface {
            public ?string $switchedTenant = null;

            public function pop(float $timeout = -1): \PDO
            {
                throw new \BadMethodCallException('Not used in this test.');
            }

            public function push(\PDO $connection): void
            {
            }

            public function close(): void
            {
            }

            public function getSize(): int
            {
                return 1;
            }

            public function getAvailable(): int
            {
                return 0;
            }

            public function switchTo(string $tenantId): void
            {
                $this->switchedTenant = $tenantId;
            }

            public function supportsTenantSwitch(): bool
            {
                return true;
            }
        };
        $this->injectPool($listener, $pool);

        $listener->handle(new TenantResolved(TenantContext::fromResolution('platform', 'domain', 'platform.semitexa.test')));

        $this->assertSame('platform', $pool->switchedTenant);
    }

    #[Test]
    public function it_keeps_switching_legacy_pools_without_capability_interface(): void
    {
        $listener = new TenantResolvedConnectionListener();
        $pool = new class implements ConnectionPoolInterface {
            public ?string $switchedTenant = null;

            public function pop(float $timeout = -1): \PDO
            {
                throw new \BadMethodCallException('Not used in this test.');
            }

            public function push(\PDO $connection): void
            {
            }

            public function close(): void
            {
            }

            public function getSize(): int
            {
                return 1;
            }

            public function getAvailable(): int
            {
                return 0;
            }

            public function switchTo(string $tenantId): void
            {
                $this->switchedTenant = $tenantId;
            }
        };
        $this->injectPool($listener, $pool);

        $listener->handle(new TenantResolved(TenantContext::fromResolution('legacy', 'domain', 'legacy.semitexa.test')));

        $this->assertSame('legacy', $pool->switchedTenant);
    }

    #[Test]
    public function it_logs_switch_failures_with_injected_logger(): void
    {
        $listener = new TenantResolvedConnectionListener();
        $this->injectPool($listener, $this->failingPool());
        $logger = new class implements LoggerInterface {
            public ?string $warningMessage = null;

            /** @var array<string, mixed> */
            public array $warningContext = [];

            public function error(string $message, array $context = []): void {}
            public function critical(string $message, array $context = []): void {}
            public function warning(string $message, array $context = []): void
            {
                $this->warningMessage = $message;
                $this->warningContext = $context;
            }
            public function info(string $message, array $context = []): void {}
            public function notice(string $message, array $context = []): void {}
            public function debug(string $message, array $context = []): void {}
        };
        $this->injectLogger($listener, $logger);

        $listener->handle(new TenantResolved(TenantContext::fromResolution('tenant-a', 'domain', 'tenant-a.test')));

        $this->assertSame('Failed to switch connection pool to tenant', $logger->warningMessage);
        $this->assertSame('tenant-a', $logger->warningContext['tenant'] ?? null);
        $this->assertSame(\LogicException::class, $logger->warningContext['exception'] ?? null);
    }

    #[Test]
    public function it_logs_switch_failures_with_fallback_logger_when_logger_is_not_injected(): void
    {
        $listener = new TenantResolvedConnectionListener();
        $this->injectPool($listener, $this->failingPool());

        $logFile = sys_get_temp_dir() . '/semitexa-orm-fallback-' . uniqid('', true) . '.log';
        $previousLogErrors = ini_get('log_errors');
        $previousErrorLog = ini_get('error_log');
        ini_set('log_errors', '1');
        ini_set('error_log', $logFile);

        try {
            $listener->handle(new TenantResolved(TenantContext::fromResolution('tenant-b', 'domain', 'tenant-b.test')));
            $contents = is_file($logFile) ? (string) file_get_contents($logFile) : '';
        } finally {
            ini_set('log_errors', is_string($previousLogErrors) ? $previousLogErrors : '1');
            ini_set('error_log', is_string($previousErrorLog) ? $previousErrorLog : '');
            if (is_file($logFile)) {
                unlink($logFile);
            }
        }

        $this->assertStringContainsString('Failed to switch connection pool to tenant', $contents);
        $this->assertStringContainsString('tenant=tenant-b', $contents);
    }

    private function injectPool(TenantResolvedConnectionListener $listener, ConnectionPoolInterface $pool): void
    {
        $reflection = new \ReflectionProperty($listener, 'connectionPool');
        $reflection->setValue($listener, $pool);
    }

    private function injectLogger(TenantResolvedConnectionListener $listener, LoggerInterface $logger): void
    {
        $reflection = new \ReflectionProperty($listener, 'logger');
        $reflection->setValue($listener, $logger);
    }

    private function failingPool(): ConnectionPoolInterface
    {
        return new class implements ConnectionPoolInterface {
            public function pop(float $timeout = -1): \PDO
            {
                throw new \BadMethodCallException('Not used in this test.');
            }

            public function push(\PDO $connection): void
            {
            }

            public function close(): void
            {
            }

            public function getSize(): int
            {
                return 1;
            }

            public function getAvailable(): int
            {
                return 0;
            }

            public function switchTo(string $tenantId): void
            {
                throw new \LogicException('Tenant database switching is not configured.');
            }
        };
    }
}

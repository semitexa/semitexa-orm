<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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

    private function injectPool(TenantResolvedConnectionListener $listener, ConnectionPoolInterface $pool): void
    {
        $reflection = new \ReflectionProperty($listener, 'connectionPool');
        $reflection->setValue($listener, $pool);
    }
}

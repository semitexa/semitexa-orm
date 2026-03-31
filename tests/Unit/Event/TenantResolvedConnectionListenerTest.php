<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\ConnectionPoolInterface;
use Semitexa\Orm\Event\TenantResolvedConnectionListener;
use Semitexa\Tenancy\Context\TenantContext;
use Semitexa\Tenancy\Event\TenantResolved;

final class TenantResolvedConnectionListenerTest extends TestCase
{
    #[Test]
    public function it_ignores_unsupported_connection_switching_for_resolved_tenant(): void
    {
        $listener = new TenantResolvedConnectionListener();
        $this->injectPool($listener, new class implements ConnectionPoolInterface {
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
        });

        $listener->handle(new TenantResolved(TenantContext::fromResolution('os', 'domain', 'os.semitexa.test')));

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function it_switches_when_pool_supports_tenant_connection_switching(): void
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

        $listener->handle(new TenantResolved(TenantContext::fromResolution('platform', 'domain', 'platform.semitexa.test')));

        $this->assertSame('platform', $pool->switchedTenant);
    }

    private function injectPool(TenantResolvedConnectionListener $listener, ConnectionPoolInterface $pool): void
    {
        $reflection = new \ReflectionProperty($listener, 'connectionPool');
        $reflection->setValue($listener, $pool);
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tenant;

use Semitexa\Core\Tenant\Scope\TenantScopeInterface;
use Semitexa\Orm\Adapter\ConnectionPoolInterface;
use Semitexa\Orm\Adapter\SchemaSwitchInterface;

/**
 * Maps strategy name from #[TenantScoped(strategy: '...')] to TenantScopeInterface implementation.
 * Use when wiring repositories so they get the correct scope from the attribute.
 */
final class TenantScopeFactory
{
    public function __construct(
        private readonly ?ConnectionPoolInterface $connectionPool = null,
        private readonly ?SchemaSwitchInterface $schemaSwitch = null,
    ) {}

    public function fromStrategy(string $strategy, string $tenantColumn = 'tenant_id'): ?TenantScopeInterface
    {
        return match ($strategy) {
            'same_storage' => new SameStorageStrategy($tenantColumn),
            'connection_switch', 'separate_db' => $this->connectionPool !== null
                ? new ConnectionSwitchStrategy($this->connectionPool)
                : null,
            'separate_schema' => $this->schemaSwitch !== null
                ? new SeparateSchemaStrategy($this->schemaSwitch)
                : null,
            default => null,
        };
    }
}

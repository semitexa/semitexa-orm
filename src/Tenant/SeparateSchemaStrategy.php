<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tenant;

use Semitexa\Core\Tenant\Scope\TenantScopeInterface;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Core\Tenant\Layer\OrganizationLayer;
use Semitexa\Orm\Adapter\SchemaSwitchInterface;

/**
 * Tenant isolation by switching the active schema (database) on the current connection.
 * Use when each tenant has a separate schema on the same DB server.
 */
final class SeparateSchemaStrategy implements TenantScopeInterface
{
    public function __construct(
        private readonly SchemaSwitchInterface $schemaSwitch,
    ) {}

    public function apply(object $queryBuilder, TenantContextInterface $context): void
    {
        $organization = $context->getLayer(new OrganizationLayer());

        if ($organization === null) {
            return;
        }

        $tenantId = $organization->rawValue();

        if ($tenantId === 'default') {
            return;
        }

        $this->schemaSwitch->useSchema($tenantId);
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tenant;

use Semitexa\Core\Tenant\Scope\TenantScopeInterface;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Core\Tenant\Layer\OrganizationLayer;

final class SameStorageStrategy implements TenantScopeInterface
{
    public function __construct(
        private readonly string $tenantColumn = 'tenant_id',
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

        $queryBuilder->where($this->tenantColumn, '=', $tenantId);
    }
}

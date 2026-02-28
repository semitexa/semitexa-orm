<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tenant;

use Semitexa\Core\Tenant\Scope\TenantScopeInterface;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Core\Tenant\Layer\OrganizationLayer;
use Semitexa\Orm\Query\WhereCapableInterface;

final class SameStorageStrategy implements TenantScopeInterface, ColumnInjectingScope
{
    public function __construct(
        private readonly string $tenantColumn = 'tenant_id',
    ) {}

    public function apply(object $queryBuilder, TenantContextInterface $context): void
    {
        if (!$queryBuilder instanceof WhereCapableInterface) {
            throw new \InvalidArgumentException(
                static::class . '::apply() requires a ' . WhereCapableInterface::class . ' instance, got ' . $queryBuilder::class . '.',
            );
        }

        $tenantId = $this->resolveTenantId($context);

        if ($tenantId === null) {
            return;
        }

        $queryBuilder->where($this->tenantColumn, '=', $tenantId);
    }

    public function injectColumns(array &$data, TenantContextInterface $context): void
    {
        $tenantId = $this->resolveTenantId($context);

        if ($tenantId === null) {
            return;
        }

        $data[$this->tenantColumn] = $tenantId;
    }

    private function resolveTenantId(TenantContextInterface $context): ?string
    {
        $organization = $context->getLayer(new OrganizationLayer());

        if ($organization === null) {
            return null;
        }

        $tenantId = $organization->rawValue();

        return $tenantId === 'default' ? null : $tenantId;
    }
}

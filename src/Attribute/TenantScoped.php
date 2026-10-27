<?php

declare(strict_types=1);

namespace Semitexa\Orm\Attribute;

use Attribute;

/**
 * A model whose rows belong to a tenant: every read is filtered on its tenant
 * column (`$column`, a property or column the model declares) and every write
 * fills it, in the shared table.
 *
 * That is the only way the ORM scopes a model. 'separate_db', 'separate_schema'
 * and 'connection_switch' were accepted and ignored: such a model read and
 * wrote the shared table as if it said 'same_storage'. They are refused now.
 * A database per tenant is a connection setting, not a model's: the
 * connection pool switches on TenantResolved (TenantResolvedConnectionListener).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class TenantScoped
{
    public const SAME_STORAGE = 'same_storage';

    public string $strategy;

    public function __construct(
        string $strategy = self::SAME_STORAGE,
        public ?string $column = null,
    ) {
        // 'column' says the same thing, and models already use it.
        if ($strategy !== self::SAME_STORAGE && $strategy !== 'column') {
            throw new \InvalidArgumentException("#[TenantScoped(strategy: '{$strategy}')] is not supported: a tenant's rows live in the shared table, filtered on its tenant column ('same_storage'). For a database per tenant, switch the connection (TenantResolved), not the model.");
        }
        $this->strategy = self::SAME_STORAGE;
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Orm;

use Semitexa\Core\Attribute\Capability;

/**
 * What this package offers, for the capability catalog.
 *
 * Without this the package is invisible to anyone whose project has not
 * installed it - which is precisely the audience worth telling, since they are
 * the ones about to build it by hand. The convention is one `Capabilities` class
 * per package: a definite place to look, and a definite place for a guard to
 * check.
 *
 * Nothing reads this at runtime.
 */
#[Capability(
    id: 'orm.persistence',
    summary: 'Attribute-driven persistence: tables, relations and queries derived from the domain model itself.',
    useWhen: 'Anything outlives the request - entities, relations, or a schema that has to keep up with the code.',
    avoidWhen: 'A single throwaway read against an existing table. A one-line query needs no mapping layer.',
    replaces: [
        'hand-written PDO queries plus a bespoke row-to-object mapper',
        'a migration file written by hand for every column added to a model',
    ],
    usage: <<<'USAGE'
        // The table is declared on the class, and bin/semitexa orm:sync creates or alters it.
        #[FromTable(name: 'orders')]
        #[TenantScoped(strategy: 'same_storage', column: 'tenantId')]
        final readonly class OrderResource
        {
            public function __construct(
                #[PrimaryKey(strategy: 'uuid')]
                #[Column(type: MySqlType::Varchar, length: 36)]
                public string $id,
                #[Column(type: MySqlType::Varchar, length: 64)]
                public string $tenantId,
                #[Column(type: MySqlType::Varchar, length: 32)]
                public string $status,
            ) {}
        }

        // Read through a service. Column names go through ColumnRef, which refuses an unknown one.
        #[AsService]
        final class OrderStore
        {
            use OrmBackedStore;

            #[InjectAsReadonly]
            protected OrmManager $orm;

            public function open(string $tenantId): array
            {
                return $this->domainRepository(OrderResource::class)
                    ->forTenant($tenantId)
                    ->query()
                    ->where(ColumnRef::for(OrderResource::class, 'status'), Operator::Equals, 'open')
                    ->fetchAll();
            }
        }

        // A #[TenantScoped] read without forTenant() throws: there is no silent global read.
        // Injection is refused inside a trait, so $orm lives on the class.
        // insert(), update() and delete() on the repository are atomic with their owned children.
        // Rows come back as the resource. A separate business object takes a second class argument,
        // an #[AsMapper] class between the two, and fetchAllAs().
        USAGE,
)]
final class Capabilities
{
}

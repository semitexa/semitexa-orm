# Semitexa ORM — Working Guide

The practical manual for the package: how to declare resources, query,
load relations, write aggregates, and wire a store. The structural rules
live in [MODULE_STRUCTURE.md](MODULE_STRUCTURE.md); this file is about
using the ORM day to day.

## Concepts in one paragraph

A **resource model** is a `final readonly` class mapped to ONE table via
attributes (`#[FromTable]`, `#[Column]`, `#[PrimaryKey]`). A **domain
model** is your business object; an `#[AsMapper]` class converts between
the two, and `MapperRegistry` routes those conversions. A
`DomainRepository` gives you reads (`findById`, `findBy`, `query()`) and
writes (`insert`/`update`/`delete`) in domain-model terms. The ORM is
deliberately **single-table**: no JOINs — relations load as separate
batched queries, and anything genuinely multi-table is a hand-written
SQL decision, not an accident.

## Declaring a resource

```php
#[FromTable(name: 'orders')]
#[TenantScoped(strategy: 'column', column: 'tenantId')]
#[SoftDelete(column: 'deletedAt')]
final readonly class OrderResource
{
    use HasColumnReferences;
    use HasRelationReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]              // or 'auto' for AUTO_INCREMENT
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,

        #[Column(type: MySqlType::Varchar, length: 64)]
        public string $tenantId,

        #[Column(type: MySqlType::Varchar, length: 32)]
        public string $status,

        #[Column(type: MySqlType::Int)]
        public int $amount,

        #[Version]                                    // optimistic locking (see Writes)
        #[Column(type: MySqlType::Int)]
        public int $version = 1,

        #[Column(type: MySqlType::Datetime, nullable: true)]
        public ?DateTimeImmutable $deletedAt = null,

        #[HasMany(target: OrderItemResource::class, foreignKey: 'orderId',
                  writePolicy: RelationWritePolicy::CascadeOwned)]
        public ?RelationState $items = null,
    ) {}
}
```

`bin/semitexa orm:sync` creates/alters the table from this declaration
(FKs, indexes, two-phase destructive ops behind `--allow-destructive`).

## Queries

`$repository->query()` returns a `ResourceModelQuery`. Column and
relation references are TYPED — `ColumnRef::for(Class, 'prop')` /
`RelationRef::for(Class, 'prop')` throw on unknown members, and using a
ref from another class in a query is rejected.

```php
$orders = $repo->query()
    ->where(ColumnRef::for(OrderResource::class, 'status'), Operator::Equals, 'open')
    ->whereIn(ColumnRef::for(OrderResource::class, 'tenantId'), $ids)
    ->orderBy(ColumnRef::for(OrderResource::class, 'amount'), Direction::Desc)
    ->limit(50)
    ->fetchAllAs(OrderDomain::class);           // mapper registry defaults to the repo's
```

Available: `where` (eq/neq/gt/gte/lt/lte/like/notlike), `whereIn`,
`whereNotIn`, `whereNull`/`whereNotNull`, `whereBetween`, `whereAnyLike`
(OR-group), `whereRaw` (placeholder-scanned escape hatch), offset
pagination (`paginate`) and keyset cursors (through the collection feed
compiler).

### Aggregations (single-table)

```php
$total   = $q->sum(ColumnRef::for(OrderResource::class, 'amount'));   // 0 on empty
$mean    = $q->avg(...);                                              // null on empty
$oldest  = $q->min(...);  $newest = $q->max(...);                     // null on empty
$byState = $q->countBy(ColumnRef::for(OrderResource::class, 'status'));
// ['open' => 12, 'done' => 30] — same WHERE/tenant/soft-delete state as fetchAll()
```

### Tenancy is fail-closed

A query on a `#[TenantScoped]` resource without tenant context throws a
`LogicException`. Call `forTenant($value)`, or opt out explicitly with
`withoutTenantScope(SystemScopeToken)` — there is no silent global read.

## Relations

Relation properties are typed `?RelationState` and hydrate to an
UNLOADED state; loading is always explicit and always batched (one
`IN (...)` query per relation — no lazy N+1):

```php
$orders = $repo->findBy([...], relations: [
    RelationRef::for(OrderResource::class, 'items'),
    RelationRef::path(OrderResource::class, 'items.product'),   // nested: dot paths
]);
```

Dot paths recurse level by level and stay batched: `items.product` is
exactly two queries regardless of row counts. Every segment is validated
at construction.

### Write policies

Each relation declares who owns the rows on write:

| Policy | Meaning on insert/update/delete |
|---|---|
| `CascadeOwned` | Children are part of the aggregate: written/replaced/deleted with the root |
| `SyncPivotOnly` | Only the pivot table is synced (delete + chunked batch insert); related rows untouched |
| `ReferenceOnly` | Never written; the FK column must already agree with the referenced object (validated) |

## Writes

`DomainRepository::insert/update/delete` run through the
`AggregateWriteEngine`, and every call is **atomic**: root row +
cascade-owned children + pivot sync commit or roll back together
(`TransactionManager::run`; a caller already inside a transaction nests
as a savepoint). The `ResourceChangedEvent` auto-publish signal fires
strictly AFTER commit.

### Optimistic locking

Declare one int column as `#[Version]`. Updates then guard on the
version the aggregate was read with and bump it in the same statement:

```php
try {
    $repo->update($order);                 // WHERE id = ? AND version = ?
} catch (StaleAggregateException) {
    // someone committed first — re-read, reapply, retry
}
```

Without `#[Version]` updates behave as before (last write wins).

## Transactions

```php
$orm->getTransactionManager()->run(function (DatabaseAdapterInterface $tx) {
    // statements here MUST use $tx — it is bound to the transaction's
    // connection; the pooled adapter would bypass the BEGIN.
});
```

Nesting creates savepoints. Events buffered via `bufferEvent()` flush
only after the OUTER commit. All transaction state is per-coroutine
(`CoroutineLocal`) — safe under Swoole concurrency.

## Wiring a store

Use the `OrmBackedStore` trait instead of hand-rolling the ritual:

```php
#[AsService]
final class OrderStore
{
    use OrmBackedStore;

    // The injected property MUST live on the class (framework rule:
    // #[InjectAs*] is forbidden inside traits).
    #[InjectAsReadonly]
    protected OrmManager $orm;

    public function open(string $tenantId): array
    {
        return $this->domainRepository(OrderResource::class, OrderDomain::class)
            ->forTenant($tenantId)
            ->query()
            ->where(ColumnRef::for(OrderResource::class, 'status'), Operator::Equals, 'open')
            ->fetchAllAs(OrderDomain::class);
    }
}
```

`findByIdOrFail()` throws the framework `NotFoundException` (HTTP 404).

## Gotchas worth knowing

- **Never memoize what OrmManager hands out across requests** — pools
  self-heal and getters re-check; capture lazily (see the write engine's
  closure-injected dispatcher/transaction manager for the pattern).
- **CLI writes and auto-publish**: the engine resolves its event
  dispatcher per dispatch, so long-running workers keep publishing after
  late bootstrap. Don't "optimize" that closure away.
- `whereRaw` placeholders are scanned quote-aware; still, prefer typed
  refs — raw SQL bypasses column validation, not tenancy (the tenant
  gate applies regardless).
- `orm:sync` never drops without `--allow-destructive`, and drops are
  two-phase (deprecation comment first).

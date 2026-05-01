# `semitexa-orm` — Local Module-Structure Extension

This document is the **human explanation** of the executable rules at
`packages/semitexa-orm/config/module-structure.php`. The PHP file is the
single source of validation truth — Markdown alone is never executable.

## Why a local extension exists

`semitexa-orm` is the ORM framework primitive: it *defines* persistence
abstractions (adapters, repositories, query DSL, metadata reflection,
entity behavior traits) that every other Semitexa package consumes. Those
primitive layers cannot live under the consumer-package canonical structure
(`Application/Db/<Adapter>/Model`/`Repository`) because the ORM is the
package that *defines* what `<Adapter>` means — there is nothing for
`Application/Db/MySQL/Model/MysqlAdapter.php` to live as.

A local module-structure extension authorises a small, named set of
ORM-only top-level directories so that the canonical FQCNs documented in
the global `MODULE_STRUCTURE.md` (e.g. `Semitexa\Orm\Adapter\MysqlAdapter`)
remain canonical.

**Scope limit.** The extension is loaded only when validating
`packages/semitexa-orm/`. It does NOT make `Adapter/`, `Trait/`,
`Repository/`, `Query/`, `Metadata/`, or `OrmManager.php` valid in any
other package, and it does NOT apply to `src/modules/*` application
modules. Ordinary packages still fail with
`module_structure.unknown_directory` if they create those layers.

## Authorised primitives (this revision)

| Path | Mode | Allowed files |
|---|---|---|
| `src/Adapter/` | `leaf_files_only` | PascalCase PHP files |
| `src/Trait/` | `leaf_files_only` | enumerated: `FilterableTrait.php`, `HasTimestamps.php`, `HasUuid.php`, `HasUuidV7.php`, `Seedable.php`, `SoftDeletes.php` |
| `src/Repository/` | `leaf_files_only` | `*Repository.php`, `*Interface.php`, `*Result.php` |
| `src/Query/` | `leaf_files_only` | PascalCase PHP files |
| `src/Metadata/` | `leaf_files_only` | PascalCase PHP files |
| `src/OrmManager.php` | root file | the file itself |

All directory entries are leaf — no subdirectories are allowed under any
of them. Adding a subdirectory requires editing the local config to either
flip the rule to `deep_validated` with explicit children or to enumerate
the new feature group, in lockstep with this document.

## Resolved by canonical split (no local extension needed)

These ORM directories were split into existing global canonical layers —
they do NOT appear in the local extension above because every file in
them fit cleanly into existing global structure.

### Phase 2 (2026-05-01)

| Old path | Files | New canonical home |
|---|---:|---|
| `src/Schema/SchemaComparatorInterface.php` | 1 | `src/Domain/Contract/SchemaComparatorInterface.php` |
| `src/Schema/ForeignKeyAction.php` | 1 | `src/Domain/Enum/ForeignKeyAction.php` |
| `src/Schema/{ColumnDefinition, DbColumnState, DbIndexState, DbTableState, ForeignKeyDefinition, IndexDefinition, ResourceMetadata, SchemaDiff, TableDefinition}.php` | 9 | `src/Domain/Model/` |
| `src/Schema/{SchemaCollector, SchemaComparator, SqliteSchemaComparator}.php` | 3 | `src/Application/Service/Schema/` |
| `src/Sync/DdlOperationType.php` | 1 | `src/Domain/Enum/DdlOperationType.php` |
| `src/Sync/{DdlOperation, ExecutionPlan}.php` | 2 | `src/Domain/Model/` |
| `src/Sync/{AuditLogger, SeedRunner, SmartUpsert, SyncEngine}.php` | 4 | `src/Application/Service/Sync/` |

### Phase 3 (2026-05-01)

| Old path | Files | New canonical home |
|---|---:|---|
| `src/Uuid/Uuid7.php` | 1 | `src/Application/Service/Uuid7.php` (flat — single utility class) |
| `src/Transaction/{SingleConnectionAdapter, TransactionManager}.php` | 2 | `src/Application/Service/Transaction/` |

### Phase 4 (2026-05-01)

| Old path | Files | New canonical home |
|---|---:|---|
| `src/Hydration/RelationType.php` | 1 | `src/Domain/Enum/RelationType.php` |
| `src/Hydration/{RelationMeta, RelationState}.php` | 2 | `src/Domain/Model/` |
| `src/Hydration/{Hydrator, RelationLoader, ResourceModelHydrator, ResourceModelRelationLoader, TypeCaster}.php` | 5 | `src/Application/Service/Hydration/` |
| `src/Mapping/MapperDefinition.php` | 1 | `src/Domain/Model/MapperDefinition.php` |
| `src/Mapping/MapperRegistry.php` | 1 | `src/Application/Service/Mapping/MapperRegistry.php` |

### Phase 5 (2026-05-01)

| Old path | Files | New canonical home |
|---|---:|---|
| `src/Connection/ConnectionConfig.php` | 1 | `src/Domain/Model/ConnectionConfig.php` |
| `src/Connection/ConnectionRegistry.php` | 1 | `src/Application/Service/Connection/ConnectionRegistry.php` |
| `src/Persistence/RelationWritePolicy.php` | 1 | `src/Domain/Enum/RelationWritePolicy.php` |
| `src/Persistence/AggregateWriteEngine.php` | 1 | `src/Application/Service/Persistence/AggregateWriteEngine.php` |

### Phase 6 (2026-05-01)

| Old path | Files | New canonical home |
|---|---:|---|
| `src/Tenant/ColumnInjectingScope.php` (interface) | 1 | `src/Domain/Contract/ColumnInjectingScopeInterface.php` (renamed; matches the canonical `*Interface.php` discipline) |
| `src/Tenant/{ConnectionSwitchStrategy, SameStorageStrategy, SeparateSchemaStrategy, TenantScopeFactory}.php` | 4 | `src/Application/Service/Tenant/` |

The old top-level `src/Schema/`, `src/Sync/`, `src/Uuid/`,
`src/Transaction/`, `src/Hydration/`, `src/Mapping/`,
`src/Connection/`, `src/Persistence/`, and `src/Tenant/` directories
were removed.

## All ORM AQ directories resolved

As of Phase 6 (2026-05-01), `semitexa-orm` passes targeted ai:verify
with **0 violations**. Every original ORM AQ directory has been resolved
either via the local extension (Phase 1: Adapter, Trait, Repository,
Query, Metadata, OrmManager.php) or via canonical split into existing
Semitexa layers (Phases 2–6).

## Hard guard rails (enforced by the loader)

A local extension cannot:

- declare a top-level directory whose name is in the global
  production-pollution deny-list (`Demo`, `Sandbox`, `Playground`,
  `Example`, `Sample`, `Fake`, `Experimental`, ...);
- redeclare a canonical top-level layer (`Application`, `Domain`,
  `Configuration`, `Context`, `Update`, `Static`, `View`, `Exception`,
  `Attribute`, `Auth`, `Discovery`, `OpenApi`, `Pipeline`);
- introduce a rule for `Domain/Contract/`, `Exception/`, or `Attribute/`
  — the `*Interface.php` / `*Exception.php` / singular `Attribute/`
  conventions are non-negotiable;
- declare a top-level directory without a corresponding `pathRules` entry
  (silent skipping is forbidden).

Violating any of these makes the loader throw at boot — the package
fails to validate at all, not silently.

## Consumer-side reminder

Other packages consuming the ORM still use
`Application/Db/<Adapter>/Model/` for `*Resource`, `*ResourceModel`,
`*Mapper` files and `Application/Db/<Adapter>/Repository/` for concrete
`*Repository` implementations. `Domain/Repository/` remains forbidden
everywhere, including in `semitexa-orm` itself. The local extension
authorises `src/Repository/` (the framework abstraction), not
`Domain/Repository/`.

## See also

- Global rules: [`packages/semitexa-docs/docs/MODULE_STRUCTURE.md`](../../semitexa-docs/docs/MODULE_STRUCTURE.md)
  — section "Local package module-structure extensions"
- Global executable spec: `packages/semitexa-dev/config/module-structure.php`
- Local executable extension: [`config/module-structure.php`](../config/module-structure.php)

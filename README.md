# Semitexa ORM

Attribute-driven ORM with schema definition, connection pooling, and MySQL 8.0+ support.

> **Schema migrations are owned by this package.** `orm:diff` (preview) and `orm:sync` (apply) are the only entry points that change database structure. Other Semitexa packages — including [`semitexa/update`](../semitexa-update/README.md) — must not issue schema DDL; they call ORM through a public seam and the ORM remains the source of truth for what the schema should look like. Post-schema *data* patches (backfills, normalizations) belong to `semitexa/update`.

## Purpose

Maps PHP classes to database tables using PHP 8.4 attributes. Provides Swoole-compatible connection pooling, typed column definitions, relation mapping, and a filtering architecture with auto-indexed filterable fields.

The source of truth for schema is the entity classes themselves (`#[FromTable]`, `#[Column]`, `#[Index]`, `#[BelongsTo]`/`#[HasMany]`/etc.). There is no canonical `migrations/` directory in a Semitexa project — the diff/sync engine produces the necessary DDL from the entity model, applies safe-rename / deprecate-then-drop policies, and gates destructive operations behind an explicit operator opt-in.

## Role in Semitexa

Depends on Core and Tenancy. Depended on by Cache, Media, Scheduler, Search, Storage, Workflow, and Platform modules. Central persistence layer for all database-backed functionality.

## Key Features

- `#[FromTable]`, `#[Column]`, `#[PrimaryKey]`, `#[Index]` for schema definition
- Relations: `#[BelongsTo]`, `#[HasMany]`, `#[OneToOne]`, `#[ManyToMany]`
- `#[Filterable]` with auto-indexing and typed `filterByX()` methods
- `#[Aggregate]` for virtual computed fields
- Traits: `HasTimestamps`, `SoftDeletes`, `HasUuid`, `HasUuidV7` (BINARY(16) chronological)
- Domain mapping via `ResourceModel`, `#[AsMapper]`, and `DomainRepository`
- Swoole `Channel`-based connection pool
- MySQL 8.0+ with version detection and capability checks
- `SchemaCollector` for attribute-driven schema sync

## Notes

ORM resources go in `Application/Db/MySQL/Model/`. Domain entities live in `Domain/Model/`. The `Resource` folder is reserved for response DTOs. Connection pooling is Swoole-native using Channel-based leasing.

## Operator commands

| Command | Purpose |
|---|---|
| `orm:status` | Show driver capabilities, schema summary, and pending diff |
| `orm:diff` | Print the DDL plan to bring the database in sync with the entity model |
| `orm:sync` | Execute the DDL plan; supports `--dry-run` and `--allow-destructive` |
| `orm:seed` | Apply data seeds (fixture data) declared by entity factories |

Update orchestration calls `orm:sync` through `Semitexa\Update\Migration\OrmMigrationGatewayInterface` — never by reaching into ORM internals.

# Semitexa ORM

Attribute-driven ORM with schema definition, connection pooling, and MySQL 8.0+ support.

## Purpose

Maps PHP classes to database tables using PHP 8.4 attributes. Provides Swoole-compatible connection pooling, typed column definitions, relation mapping, and a filtering architecture with auto-indexed filterable fields.

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

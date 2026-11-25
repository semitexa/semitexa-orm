# Changelog

Changes to `semitexa/orm` that a consuming application can notice. Sections are
`## <version> — <date>` (newest first); `## Unreleased` collects changes until the
next release tag. This file is machine-read by `update:changelog` and the OS
"What's new" surface — keep entries short and operator-facing.

## 2026.11.25.1338 — 2026-11-25

### Deprecated
- `RepositoryInterface` as a whole. Nothing implements it, and `DomainRepository`, the
  ORM's repository, does not: its `findAll()` and `findBy()` take relations and a
  nullable limit the interface lacks. Type against `DomainRepository`, or declare a
  module's own repository contract and satisfy it with `#[SatisfiesRepositoryContract]`.

## 2026.11.25.0522 — 2026-11-25

### Deprecated
- `FilterableTrait`, `FilterableResourceInterface`, `RepositoryInterface::find()` and
  `findOne()`, which take the criteria such a resource collects, and
  `ResourceMetadata::getFilterableColumns()`, which only the trait reads. A `final readonly`
  resource model that uses the trait fails to load: the trait holds mutable state. A
  legacy non-readonly `#[FromTable]` class can still use it, and its own
  `getFilterCriteria()` returns what it collected, but no built-in repository reads
  those criteria. `#[Filterable]` adds an index. To filter, pass criteria to
  `findBy()` or use `query()->where()`. They will be removed in a later release.

### Fixed
- A `#[Filterable]` index is now created when another resource on the same table
  declared the column first. Before, a slice that filters by a column its base declares
  without `#[Filterable]` got no index, because the schema stopped at the column it
  already had. `orm:diff` now shows the missing index on such tables.

## 2026.10.08.0620 — 2026-10-08

### Changed
- **Owned relations must be typed `array|RelationState`.** A plain `array` is
  refused: saving a model whose relation was never loaded deleted its rows.
- **DECIMAL hydrates as an exact string** (`"19.90"`); a `float` property still
  gets a float.

### Added
- `AggregateWriteEngine::write()`, `OrmManager::query()`, `countByDay()`,
  `gte` / `lte` filters.

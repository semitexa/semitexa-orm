# Changelog

Changes to `semitexa/orm` that a consuming application can notice. Sections are
`## <version> — <date>` (newest first); `## Unreleased` collects changes until the
next release tag. This file is machine-read by `update:changelog` and the OS
"What's new" surface — keep entries short and operator-facing.

## Unreleased

### Deprecated
- `FilterableTrait`, `FilterableResourceInterface`, `RepositoryInterface::find()` and
  `findOne()`, which take the criteria such a resource collects, and
  `ResourceMetadata::getFilterableColumns()`, which only the trait reads. A `final readonly`
  resource model that uses the trait fails to load: the trait holds mutable state. A
  legacy non-readonly `#[FromTable]` class can still use it, but nothing reads the
  criteria it collects. `#[Filterable]` adds an
  index. To filter, pass criteria to `findBy()` or use `query()->where()`. They will
  be removed in a later release.

## 2026.10.08.0620 — 2026-10-08

### Changed
- **Owned relations must be typed `array|RelationState`.** A plain `array` is
  refused: saving a model whose relation was never loaded deleted its rows.
- **DECIMAL hydrates as an exact string** (`"19.90"`); a `float` property still
  gets a float.

### Added
- `AggregateWriteEngine::write()`, `OrmManager::query()`, `countByDay()`,
  `gte` / `lte` filters.

# Changelog

Changes to `semitexa/orm` that a consuming application can notice. Sections are
`## <version> — <date>` (newest first); `## Unreleased` collects changes until the
next release tag. This file is machine-read by `update:changelog` and the OS
"What's new" surface — keep entries short and operator-facing.

## Unreleased

### Changed
- **Owned relations must be typed `array|RelationState`.** A plain `array` is
  refused: saving a model whose relation was never loaded deleted its rows.
- **DECIMAL hydrates as an exact string** (`"19.90"`); a `float` property still
  gets a float.

### Added
- `AggregateWriteEngine::write()`, `OrmManager::query()`, `countByDay()`,
  `gte` / `lte` filters.

# Semitexa ORM

Attribute-driven ORM for the Semitexa Framework. Defines database schema directly in PHP 8.4 attributes, with Swoole connection pooling and MySQL 8.0+ support.

## Installation

The package is included as a path repository in the root `composer.json`:

```bash
composer require semitexa/orm:"*"
```

## Configuration

Add to `.env`:

```env
DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=semitexa
DB_USERNAME=root
DB_PASSWORD=
DB_POOL_SIZE=10
DB_CHARSET=utf8mb4
```

## Usage

### Define a Resource

```php
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Trait\HasTimestamps;

#[FromTable(name: 'users')]
#[Index(columns: ['email'], unique: true)]
class UserResource
{
    use HasTimestamps;

    #[PrimaryKey(strategy: 'auto')]
    #[Column(type: MySqlType::Int)]
    public int $id;

    #[Column(type: MySqlType::Varchar, length: 255)]
    public string $email;

    #[Column(type: MySqlType::Varchar, length: 255)]
    public string $name;
}
```

### Available Attributes

| Attribute | Target | Description |
|-----------|--------|-------------|
| `#[FromTable]` | Class | Maps class to a database table |
| `#[Column]` | Property | Defines column type, length, precision, default |
| `#[PrimaryKey]` | Property | Marks primary key with strategy (`auto`, `uuid`, `manual`) |
| `#[Index]` | Class | Defines table index (repeatable, supports `unique`) |
| `#[Deprecated]` | Property | Marks column for future removal |
| `#[BelongsTo]` | Property | Many-to-one relation |
| `#[HasMany]` | Property | One-to-many relation |
| `#[OneToOne]` | Property | One-to-one relation |
| `#[ManyToMany]` | Property | Many-to-many with pivot table |
| `#[Aggregate]` | Property | Virtual aggregated field (`COUNT`, `SUM`, etc.) |

### Traits

- `HasTimestamps` — adds `created_at`, `updated_at`
- `SoftDeletes` — adds nullable `deleted_at`
- `HasUuid` — adds `uuid` (varchar 36)

### Domain Mapping

Implement `DomainMappable` when `#[FromTable(mapTo: ...)]` is used:

```php
#[FromTable(name: 'users', mapTo: User::class)]
class UserResource implements DomainMappable
{
    public function toDomain(): User { /* ... */ }
    public static function fromDomain(object $entity): static { /* ... */ }
}
```

## Architecture

- **SchemaCollector** — discovers `#[FromTable]` classes via `ClassDiscovery`, builds `TableDefinition` objects, validates schema
- **ConnectionPool** — Swoole `Channel`-based pool with lazy creation
- **MysqlAdapter** — MySQL 8.0+ with version detection and capability checks
- **OrmManager** — orchestrator that ties pool, adapter, and schema collector together

## Requirements

- PHP 8.4+
- MySQL 8.0+
- Swoole 5.x
- `semitexa/core ^1.0`

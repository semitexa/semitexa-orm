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
| `#[Filterable]` | Property | Enables filterByX() and auto-index for this column |
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
- `FilterableTrait` — adds `filterByX($value)` for main-table properties with `#[Filterable]`, and `filterBy{Relation}{Column}($value)` for related models; implement `FilterableResourceInterface` when using with `Repository::find(object)`

### Filtering architecture

Typed factory (DI) creates a clean resource instance; the resource declares filters via `#[Filterable]` and exposes typed `filterByX()` methods; the repository accepts the prepared resource and runs the query.

**Layer responsibilities:**

- **Resource factory (DI)** — e.g. `UserResourceFactory`: creates a clean resource instance with no data (`$userFactory->create()`).
- **Resource model** — declares filterable fields with `#[Filterable]`, uses `FilterableTrait` and `FilterableResourceInterface`; `filterByX($value)` sets criteria.
- **Repository** — `find($resource)` / `findOne($resource)` accept only the repository’s resource type and execute the query from the resource’s criteria.

**Rules:**

- `filterByX()` is available only for properties with `#[Filterable]`. Calling `filterByX()` for a non-filterable property throws `BadMethodCallException`.
- For relation filters, the **related** model’s column must be marked with `#[Filterable]` (e.g. filter by `user.email` requires `email` to be filterable on the User resource).
- A DB index is created automatically for every `#[Filterable]` column.
- `Repository::find($resource)` and `findOne($resource)` accept only a resource of the repository’s type (otherwise `InvalidArgumentException`).

**Example:**

```php
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Attribute\Filterable;
use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Trait\HasTimestamps;
use Semitexa\Orm\Trait\FilterableTrait;
use Semitexa\Orm\Contract\FilterableResourceInterface;

#[FromTable(name: 'users')]
class UserResource implements FilterableResourceInterface
{
    use HasTimestamps;
    use FilterableTrait;

    #[PrimaryKey(strategy: 'auto')]
    #[Column(type: MySqlType::Int)]
    public int $id;

    #[Column(type: MySqlType::Varchar, length: 255)]
    #[Filterable]
    public string $name;

    #[Column(type: MySqlType::Varchar, length: 255)]
    #[Filterable]
    public string $email;
}

// In a handler or service (factory and repository injected via DI):
$userResource = $userFactory->create();
$userResource->filterByName('Rita');
$users = $userRepository->find($userResource);

// Or find first match:
$user = $userRepository->findOne($userResource);
```

### Filtering by related models

You can restrict results using conditions on **related** resource models. Use `filterBy{Relation}{Column}($value)` where the relation is the property name (e.g. `user`) and the column is a filterable property on the related model (e.g. `email`). Same value semantics as main table: `null` → IS NULL, array → IN, scalar → =.

**Example (BelongsTo):** find orders where the related user has a given email:

```php
#[FromTable(name: 'orders')]
class OrderResource implements FilterableResourceInterface
{
    use FilterableTrait;

    #[Column(type: MySqlType::Bigint)]
    public int $id;

    #[Column(type: MySqlType::Bigint)]
    public int $user_id;

    #[BelongsTo(target: UserResource::class, foreignKey: 'user_id')]
    public UserResource $user;
}

$orderResource = $orderFactory->create();
$orderResource->filterByUserEmail('admin@example.com');
$orders = $orderRepository->find($orderResource);
```

**Example (HasMany):** find users that have at least one order with status `paid` (use a filterable column on the related Order resource):

```php
$userResource = $userFactory->create();
$userResource->filterByOrdersStatus('paid');
$users = $userRepository->find($userResource);
```

In repository methods you can also use the query builder: `$this->select()->whereRelation('user', 'email', '=', 'x')->fetchAll()`.

**Resource factory (DI):** Implement a per-resource factory interface and bind it to `ResourceFactory`:

```php
namespace App\Resource\Factory;

use Semitexa\Orm\Factory\ResourceFactoryInterface;

interface UserResourceFactory extends ResourceFactoryInterface
{
    public function create(): \App\Resource\UserResource;
}
```

Register in your container/bootstrap: `UserResourceFactory` → `new \Semitexa\Orm\Factory\ResourceFactory(\App\Resource\UserResource::class)`.

### Foreign key default behaviour

- **Nullable FK** — `ON DELETE SET NULL`, `ON UPDATE SET NULL`
- **Not-null FK** — `ON DELETE RESTRICT`, `ON UPDATE RESTRICT`
- **Override** — set explicitly on the relation, e.g. `#[BelongsTo(User::class, foreignKey: 'owner_id', onDelete: \Semitexa\Orm\Schema\ForeignKeyAction::CASCADE)]`

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

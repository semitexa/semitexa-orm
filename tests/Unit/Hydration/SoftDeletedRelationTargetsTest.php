<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Hydration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Adapter\SqliteAdapter;
use Semitexa\Orm\Application\Service\Hydration\ResourceModelHydrator;
use Semitexa\Orm\Application\Service\Hydration\ResourceModelRelationLoader;
use Semitexa\Orm\Attribute\BelongsTo;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\HasMany;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Attribute\SoftDelete;
use Semitexa\Orm\Domain\Enum\RelationWritePolicy;
use Semitexa\Orm\Domain\Model\RelationState;
use Semitexa\Orm\Metadata\RelationRef;
use Semitexa\Orm\Query\ResourceModelQuery;

/**
 * A soft-deleted row is gone for a direct read; eager loading must not bring
 * it back as somebody's relation.
 */
final class SoftDeletedRelationTargetsTest extends TestCase
{
    private SqliteAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new SqliteAdapter('sqlite::memory:');
        $this->adapter->execute('CREATE TABLE sd_parents (id TEXT PRIMARY KEY, ownerId TEXT)');
        $this->adapter->execute('CREATE TABLE sd_children (id TEXT PRIMARY KEY, parentId TEXT, deletedAt TEXT)');
        $this->adapter->execute("INSERT INTO sd_parents VALUES ('parent-1', 'child-deleted')");
        $this->adapter->execute("INSERT INTO sd_children VALUES
            ('child-live', 'parent-1', NULL),
            ('child-deleted', 'parent-1', '2026-01-01 00:00:00')");
    }

    #[Test]
    public function has_many_leaves_out_soft_deleted_children(): void
    {
        $children = $this->parent('children')->children->value();

        self::assertSame(['child-live'], array_column($children, 'id'));
    }

    #[Test]
    public function belongs_to_a_soft_deleted_row_loads_as_absent(): void
    {
        $owner = $this->parent('owner')->owner;

        self::assertTrue($owner->isLoaded());
        self::assertNull($owner->value());
    }

    private function parent(string $relation): SoftDeleteParent
    {
        $hydrator = new ResourceModelHydrator();
        $query = new ResourceModelQuery(
            SoftDeleteParent::class,
            $this->adapter,
            $hydrator,
            new ResourceModelRelationLoader($this->adapter, $hydrator),
        );
        $parent = $query->withRelation(RelationRef::for(SoftDeleteParent::class, $relation))->fetchOne();
        self::assertInstanceOf(SoftDeleteParent::class, $parent);

        return $parent;
    }
}

#[FromTable(name: 'sd_parents')]
final readonly class SoftDeleteParent
{
    public function __construct(
        #[PrimaryKey(strategy: 'uuid')] #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,
        #[Column(type: MySqlType::Varchar, length: 36, nullable: true)]
        public ?string $ownerId,
        #[HasMany(target: SoftDeleteChild::class, foreignKey: 'parentId', writePolicy: RelationWritePolicy::ReferenceOnly)]
        public ?RelationState $children = null,
        #[BelongsTo(target: SoftDeleteChild::class, foreignKey: 'ownerId', writePolicy: RelationWritePolicy::ReferenceOnly)]
        public ?RelationState $owner = null,
    ) {}
}

#[FromTable(name: 'sd_children')]
#[SoftDelete(column: 'deletedAt')]
final readonly class SoftDeleteChild
{
    public function __construct(
        #[PrimaryKey(strategy: 'uuid')] #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,
        #[Column(type: MySqlType::Varchar, length: 36, nullable: true)]
        public ?string $parentId,
        #[Column(type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $deletedAt,
    ) {}
}

<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Factory;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Application\Service\Factory\ResourceModelFactory;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Attribute\SoftDelete;
use Semitexa\Orm\Attribute\TenantScoped;
use Semitexa\Orm\Attribute\Version;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;

enum FactoryTestStatus: string
{
    case Draft = 'draft';
    case Live = 'live';
}

#[FromTable(name: 'factory_test_items')]
final readonly class FactoryTestItemResourceModel
{
    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Binary, length: 16)]
        public string $id,
        #[Column(name: 'email', type: MySqlType::Varchar, length: 64)]
        public string $email,
        #[Column(name: 'code', type: MySqlType::Char, length: 6)]
        public string $code,
        #[Column(name: 'qty', type: MySqlType::SmallInt)]
        public int $qty,
        #[Column(name: 'price', type: MySqlType::Decimal, precision: 8, scale: 2)]
        public string $price,
        #[Column(name: 'active', type: MySqlType::Boolean)]
        public bool $active,
        #[Column(name: 'status', type: MySqlType::Varchar, length: 16)]
        public FactoryTestStatus $status,
        #[Column(name: 'published_at', type: MySqlType::Datetime)]
        public \DateTimeImmutable $publishedAt,
        #[Column(name: 'note', type: MySqlType::Text, nullable: true)]
        public ?string $note = null,
        #[Column(name: 'rank', type: MySqlType::Int, default: 5)]
        public int $rank = 5,
    ) {}
}

#[FromTable(name: 'factory_test_ledger')]
#[TenantScoped(strategy: 'same_storage', column: 'tenantId')]
#[SoftDelete(column: 'deletedAt')]
final readonly class FactoryTestLedgerResourceModel
{
    public function __construct(
        #[PrimaryKey(strategy: 'auto')]
        #[Column(type: MySqlType::Bigint)]
        public int $id,
        #[Column(name: 'tenant_id', type: MySqlType::Varchar, length: 64)]
        public string $tenantId,
        #[Column(name: 'owner_id', type: MySqlType::Binary, length: 16)]
        public string $ownerId,
        #[Column(name: 'flag', type: MySqlType::Boolean)]
        public int $flag,
        #[Version]
        #[Column(name: 'row_version', type: MySqlType::Int)]
        public int $rowVersion,
        #[Column(name: 'deleted_at', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $deletedAt = null,
    ) {}
}

final class ResourceModelFactoryTest extends TestCase
{
    #[Test]
    public function an_auto_key_is_left_to_the_database_and_comes_back_filled(): void
    {
        // review: a non-nullable int auto key was sent as 0 — SQLite stored 0
        // and refused the second row; MySQL assigned ids the models never saw.
        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $orm->getAdapter()->execute(
            'CREATE TABLE factory_test_ledger (
                id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id TEXT NOT NULL, owner_id BLOB NOT NULL,
                flag INTEGER NOT NULL, row_version INTEGER NOT NULL, deleted_at TEXT NULL
            )',
        );
        try {
            $rows = ResourceModelFactory::for(FactoryTestLedgerResourceModel::class)->forTenant('acme')->count(2)->create($orm);
            $more = ResourceModelFactory::for(FactoryTestLedgerResourceModel::class)->forTenant('acme')->createOne($orm);

            self::assertSame([1, 2, 3], [$rows[0]->id, $rows[1]->id, $more->id]);
            self::assertSame('acme', $rows[0]->tenantId);
            self::assertContains($rows[0]->flag, [0, 1], 'an int on a Boolean column is 0 or 1');
            self::assertSame(0, $rows[0]->rowVersion, 'a version column starts at 0');
            self::assertSame(3, (int) $orm->getAdapter()->query('SELECT COUNT(*) AS n FROM factory_test_ledger')->fetchAll()[0]['n']);
        } finally {
            $orm->shutdown();
        }

        $this->expectException(\LogicException::class);
        ResourceModelFactory::for(FactoryTestLedgerResourceModel::class)->create(new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true)));
    }

    #[Test]
    public function separate_calls_never_repeat_a_row_and_a_seed_repeats_everything(): void
    {
        // review: every call started from row 0 with seed 1: two createOne()
        // calls collided on a unique email.
        $first = ResourceModelFactory::for(FactoryTestItemResourceModel::class)->makeOne();
        $second = ResourceModelFactory::for(FactoryTestItemResourceModel::class)->makeOne();
        self::assertNotSame($first->email, $second->email);

        $a = ResourceModelFactory::for(FactoryTestLedgerResourceModel::class)->seed(7)->forTenant('t')->makeOne();
        $b = ResourceModelFactory::for(FactoryTestLedgerResourceModel::class)->seed(7)->forTenant('t')->makeOne();
        $c = ResourceModelFactory::for(FactoryTestLedgerResourceModel::class)->seed(8)->forTenant('t')->makeOne();
        self::assertSame($a->ownerId, $b->ownerId, 'a uuid foreign key follows the seed too');
        self::assertNotSame($a->ownerId, $c->ownerId);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $a->ownerId);
    }

    #[Test]
    public function filled_never_soft_deletes_and_count_zero_is_none(): void
    {
        $row = ResourceModelFactory::for(FactoryTestLedgerResourceModel::class)->forTenant('t')->filled()->makeOne();

        self::assertNull($row->deletedAt, 'filled, a soft-delete column hid every row');
        self::assertSame([], ResourceModelFactory::for(FactoryTestItemResourceModel::class)->count(0)->make());
    }

    #[Test]
    public function rows_are_valid_for_their_columns_and_apart_from_each_other(): void
    {
        $items = ResourceModelFactory::for(FactoryTestItemResourceModel::class)->count(20)->make();

        self::assertCount(20, $items);
        $emails = array_map(static fn ($i): string => $i->email, $items);
        self::assertCount(20, array_unique($emails), 'a sequence of rows does not trip a unique email');
        foreach ($items as $item) {
            self::assertSame('', $item->id, 'a uuid key is left for the write to fill');
            self::assertMatchesRegularExpression('/^user\d+@example\.test$/', $item->email);
            self::assertLessThanOrEqual(6, strlen($item->code));
            self::assertLessThanOrEqual(32_767, $item->qty);
            self::assertMatchesRegularExpression('/^\d{1,6}\.\d{2}$/', $item->price);
            self::assertInstanceOf(FactoryTestStatus::class, $item->status);
            self::assertNull($item->note, 'nullable stays null unless filled()');
            self::assertSame(5, $item->rank, 'the declared default');
        }
        self::assertNotNull(ResourceModelFactory::for(FactoryTestItemResourceModel::class)->filled()->makeOne()->note);
    }

    #[Test]
    public function state_beats_sequence_beats_generated_and_a_seed_repeats(): void
    {
        $items = ResourceModelFactory::for(FactoryTestItemResourceModel::class)
            ->count(3)
            ->sequence(static fn (int $i): array => ['qty' => $i, 'rank' => 10 + $i])
            ->state(['rank' => 99])
            ->state(static fn (int $i): array => ['code' => "C{$i}"])
            ->make();

        self::assertSame([0, 1, 2], array_map(static fn ($i): int => $i->qty, $items));
        self::assertSame([99, 99, 99], array_map(static fn ($i): int => $i->rank, $items));
        self::assertSame(['C0', 'C1', 'C2'], array_map(static fn ($i): string => $i->code, $items));

        $a = ResourceModelFactory::for(FactoryTestItemResourceModel::class)->seed(7)->makeOne();
        $b = ResourceModelFactory::for(FactoryTestItemResourceModel::class)->seed(7)->makeOne();
        self::assertEquals($a, $b);

        $this->expectException(\InvalidArgumentException::class);
        ResourceModelFactory::for(FactoryTestItemResourceModel::class)->state(['nope' => 1])->makeOne();
    }

    #[Test]
    public function create_writes_the_rows_and_fills_the_uuid_key(): void
    {
        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $orm->getAdapter()->execute(
            'CREATE TABLE factory_test_items (
                id BLOB PRIMARY KEY, email TEXT NOT NULL, code TEXT NOT NULL, qty INTEGER NOT NULL, price TEXT NOT NULL,
                active INTEGER NOT NULL, status TEXT NOT NULL, published_at TEXT NOT NULL, note TEXT NULL, "rank" INTEGER NOT NULL
            )',
        );
        try {
            $created = ResourceModelFactory::for(FactoryTestItemResourceModel::class)->count(2)->create($orm);

            self::assertCount(2, $created);
            self::assertNotSame('', $created[0]->id);
            self::assertNotSame($created[0]->id, $created[1]->id);
            $rows = $orm->getAdapter()->query('SELECT email, status FROM factory_test_items ORDER BY email')->fetchAll();
            self::assertCount(2, $rows);
            self::assertContains($rows[0]['status'], ['draft', 'live']);
        } finally {
            $orm->shutdown();
        }
    }
}

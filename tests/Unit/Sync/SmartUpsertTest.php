<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Sync;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Application\Service\Sync\SmartUpsert;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Tests\Fixture\Hydration\FakeDatabaseAdapter;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidProductResourceModel;

/**
 * SmartUpsert dehydrates through the ONE shared ResourceModelHydrator (it was
 * the last consumer of the deleted legacy Hydrator). These pins prove the
 * migration preserved the row shape: column-name keys, PK-less rows skipped,
 * one multi-row INSERT … ON DUPLICATE KEY UPDATE per batch.
 */
final class SmartUpsertTest extends TestCase
{
    #[Test]
    public function upsert_builds_one_on_duplicate_key_statement_from_dehydrated_rows(): void
    {
        $adapter = new FakeDatabaseAdapter([]);
        $upsert = new SmartUpsert($adapter);

        $upsert->upsert([
            $this->product('p-1', 'One'),
            $this->product('p-2', 'Two'),
        ]);

        self::assertCount(1, $adapter->executed, 'A batch must be one statement.');
        $sql = $adapter->executed[0]['sql'];
        self::assertStringContainsString('INSERT INTO `products`', $sql);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);

        $params = $adapter->executed[0]['params'];
        self::assertSame('p-1', $params['r0_id']);
        self::assertSame('p-2', $params['r1_id']);
        self::assertSame('One', $params['r0_name']);
        self::assertSame('Two', $params['r1_name']);
    }

    #[Test]
    public function rows_without_a_primary_key_value_are_skipped(): void
    {
        $adapter = new FakeDatabaseAdapter([]);
        $upsert = new SmartUpsert($adapter);

        $result = $upsert->upsert([new NullablePkUpsertFixture(id: null, name: 'No PK')]);

        self::assertSame(['inserted' => 0, 'updated' => 0, 'unchanged' => 0], $result);
        self::assertCount(0, $adapter->executed);
    }

    private function product(string $id, string $name): ValidProductResourceModel
    {
        return new ValidProductResourceModel(
            id: $id,
            tenantId: 'tenant-1',
            name: $name,
            categoryId: 'category-1',
        );
    }
}

#[FromTable(name: 'upsert_fixture')]
final readonly class NullablePkUpsertFixture
{
    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Varchar, length: 36, nullable: true)]
        public ?string $id,

        #[Column(type: MySqlType::Varchar, length: 255)]
        public string $name,
    ) {}
}

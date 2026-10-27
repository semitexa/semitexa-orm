<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Persistence;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Application\Service\Mapping\MapperRegistry;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Attribute\Version;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;

/**
 * A delete bound a UUID key as its text while the column holds its 16 bytes,
 * as insert and update write it: the row was never found. With #[Version] it
 * failed as a stale write (platform_user's delete never worked); without, it
 * deleted nothing and said nothing.
 */
final class BinaryUuidKeyDeleteTest extends TestCase
{
    #[Test]
    public function a_row_with_a_binary_uuid_key_is_deleted(): void
    {
        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $orm->getAdapter()->execute('CREATE TABLE bu_accounts (id BLOB PRIMARY KEY, name TEXT, row_version INTEGER)');
        $id = Uuid7::generate();
        $orm->getAdapter()->execute('INSERT INTO bu_accounts VALUES (:id, :name, 0)', ['id' => Uuid7::toBytes($id), 'name' => 'a']);
        $registry = new MapperRegistry();
        $registry->build(mapperClasses: [BuAccountMapper::class], domainModelClasses: [BuAccountDomain::class]);

        $orm->getAggregateWriteEngine()->delete(new BuAccountDomain($id, 'a', 0), BuAccountFixture::class, $registry);

        self::assertSame([], $orm->getAdapter()->query('SELECT id FROM bu_accounts')->rows);
    }
}

final readonly class BuAccountDomain
{
    public function __construct(public string $id, public string $name, public int $version) {}
}

#[FromTable(name: 'bu_accounts')]
final readonly class BuAccountFixture
{
    use \Semitexa\Orm\Metadata\HasColumnReferences;
    use \Semitexa\Orm\Metadata\HasRelationReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Binary, length: 16)]
        public string $id,
        #[Column(type: MySqlType::Varchar, length: 64)]
        public string $name,
        #[Version]
        #[Column(name: 'row_version', type: MySqlType::Int)]
        public int $version = 0,
    ) {}
}

#[AsMapper(resourceModel: BuAccountFixture::class, domainModel: BuAccountDomain::class)]
final class BuAccountMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof BuAccountFixture || throw new \InvalidArgumentException('Unexpected resource model.');

        return new BuAccountDomain($resourceModel->id, $resourceModel->name, $resourceModel->version);
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof BuAccountDomain || throw new \InvalidArgumentException('Unexpected domain model.');

        return new BuAccountFixture($domainModel->id, $domainModel->name, $domainModel->version);
    }
}

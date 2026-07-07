<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Persistence;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Attribute\Version;
use Semitexa\Orm\Application\Service\Mapping\MapperRegistry;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\Exception\StaleAggregateException;
use Semitexa\Orm\OrmManager;

/**
 * #[Version] optimistic locking: an UPDATE guards on the version the caller
 * read and bumps it atomically; a writer working from a stale read throws
 * StaleAggregateException instead of silently overwriting the newer state.
 * Exercised on real SQLite through the full engine (transactional) path.
 */
final class OptimisticLockingTest extends TestCase
{
    private OrmManager $orm;

    protected function setUp(): void
    {
        $this->orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $this->orm->getAdapter()->execute(
            'CREATE TABLE ol_notes (id TEXT PRIMARY KEY, body TEXT, version INTEGER)'
        );
        $this->orm->getAdapter()->execute("INSERT INTO ol_notes VALUES ('n1', 'first', 1)");
    }

    #[Test]
    public function an_up_to_date_write_bumps_the_version(): void
    {
        $engine = $this->orm->getAggregateWriteEngine();
        $engine->update(new OlNoteDomain('n1', 'edited', 1), OlNoteFixture::class, $this->registry());

        $row = $this->orm->getAdapter()->query('SELECT body, version FROM ol_notes')->rows[0];
        self::assertSame('edited', $row['body']);
        self::assertSame(2, (int) $row['version']);
    }

    #[Test]
    public function a_stale_write_throws_instead_of_overwriting(): void
    {
        $engine = $this->orm->getAggregateWriteEngine();
        // Writer A commits from version 1 → row is now version 2.
        $engine->update(new OlNoteDomain('n1', 'from A', 1), OlNoteFixture::class, $this->registry());

        // Writer B still holds version 1 — must NOT win.
        try {
            $engine->update(new OlNoteDomain('n1', 'from B', 1), OlNoteFixture::class, $this->registry());
            self::fail('A stale-version update must throw StaleAggregateException.');
        } catch (StaleAggregateException) {
            // expected
        }

        $row = $this->orm->getAdapter()->query('SELECT body, version FROM ol_notes')->rows[0];
        self::assertSame('from A', $row['body'], "Writer A's state must survive.");
        self::assertSame(2, (int) $row['version']);
    }

    #[Test]
    public function updating_a_deleted_row_also_throws(): void
    {
        $this->orm->getAdapter()->execute("DELETE FROM ol_notes WHERE id = 'n1'");

        $this->expectException(StaleAggregateException::class);
        $this->orm->getAggregateWriteEngine()
            ->update(new OlNoteDomain('n1', 'ghost', 1), OlNoteFixture::class, $this->registry());
    }

    private function registry(): MapperRegistry
    {
        $registry = new MapperRegistry();
        $registry->build(
            mapperClasses: [OlNoteMapper::class],
            domainModelClasses: [OlNoteDomain::class],
        );

        return $registry;
    }
}

final readonly class OlNoteDomain
{
    public function __construct(
        public string $id,
        public string $body,
        public int $version,
    ) {}
}

#[FromTable(name: 'ol_notes')]
final readonly class OlNoteFixture
{
    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,

        #[Column(type: MySqlType::Varchar, length: 255)]
        public string $body,

        #[Version]
        #[Column(type: MySqlType::Int)]
        public int $version,
    ) {}
}

#[AsMapper(resourceModel: OlNoteFixture::class, domainModel: OlNoteDomain::class)]
final class OlNoteMapper implements \Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof OlNoteFixture || throw new \InvalidArgumentException('Unexpected resource model.');

        return new OlNoteDomain($resourceModel->id, $resourceModel->body, $resourceModel->version);
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof OlNoteDomain || throw new \InvalidArgumentException('Unexpected domain model.');

        return new OlNoteFixture($domainModel->id, $domainModel->body, $domainModel->version);
    }
}

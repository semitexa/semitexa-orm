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
use Semitexa\Orm\Application\Service\Persistence\AggregateWriteEngine;
use Semitexa\Orm\Application\Service\Hydration\ResourceModelHydrator;

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
        $this->orm->getAdapter()->execute(
            'CREATE TABLE ol_comments (id TEXT PRIMARY KEY, noteId TEXT, body TEXT)'
        );
        $this->orm->getAdapter()->execute("INSERT INTO ol_notes VALUES ('n1', 'first', 1)");
        $this->orm->getAdapter()->execute("INSERT INTO ol_comments VALUES ('c1', 'n1', 'a comment')");
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
    public function update_returns_the_bumped_aggregate_so_sequential_updates_work(): void
    {
        $engine = $this->orm->getAggregateWriteEngine();

        $note = new OlNoteDomain('n1', 'v2', 1);
        $note = $engine->update($note, OlNoteFixture::class, $this->registry());
        self::assertInstanceOf(OlNoteDomain::class, $note);
        self::assertSame(2, $note->version, 'update() must hand back the bumped version.');

        // The returned aggregate is immediately updatable again — no self-stale.
        $note = $engine->update(new OlNoteDomain('n1', 'v3', $note->version), OlNoteFixture::class, $this->registry());
        self::assertSame(3, $note->version);

        $row = $this->orm->getAdapter()->query('SELECT body, version FROM ol_notes')->rows[0];
        self::assertSame('v3', $row['body']);
        self::assertSame(3, (int) $row['version']);
    }

    #[Test]
    public function a_non_readonly_version_resource_is_rejected_before_any_write(): void
    {
        // Guards why readonly-only fixtures suffice above: a non-readonly
        // resource would be version-bumped IN PLACE (same instance), which
        // the identity path in update() could misread as "no bump". The
        // metadata contract forbids that shape outright — fail-fast, never
        // a silently stale return.
        $registry = new MapperRegistry();
        $registry->build(
            mapperClasses: [OlMutableNoteMapper::class],
            domainModelClasses: [OlNoteDomain::class],
        );

        $this->expectException(\Semitexa\Orm\Exception\InvalidResourceModelException::class);
        $this->expectExceptionMessage('must be readonly');
        $this->orm->getAggregateWriteEngine()
            ->update(new OlNoteDomain('n1', 'v2', 1), OlMutableNoteFixture::class, $registry);
    }

    #[Test]
    public function updating_a_deleted_row_also_throws(): void
    {
        $this->orm->getAdapter()->execute("DELETE FROM ol_notes WHERE id = 'n1'");

        $this->expectException(StaleAggregateException::class);
        $this->orm->getAggregateWriteEngine()
            ->update(new OlNoteDomain('n1', 'ghost', 1), OlNoteFixture::class, $this->registry());
    }

    #[Test]
    public function deleting_with_a_stale_version_cannot_remove_a_newer_row(): void
    {
        $engine = $this->orm->getAggregateWriteEngine();
        $engine->update(new OlNoteDomain('n1', 'newer', 1), OlNoteFixture::class, $this->registry());

        try {
            $engine->delete(new OlNoteDomain('n1', 'first', 1), OlNoteFixture::class, $this->registry());
            self::fail('A stale-version delete must throw StaleAggregateException.');
        } catch (StaleAggregateException) {
            // expected
        }

        self::assertSame(
            1,
            (int) $this->orm->getAdapter()->query('SELECT COUNT(*) AS c FROM ol_notes')->rows[0]['c'],
        );
    }

    #[Test]
    public function a_stale_delete_is_refused_before_anything_is_removed(): void
    {
        // A cascade delete removes owned rows FIRST and guards the root DELETE
        // on #[Version], so a stale root used to throw only after the children
        // were already gone. Inside a transaction the rollback hides that;
        // without a TransactionManager — the documented legacy path, and what
        // a hand-built engine gets — nothing undoes it, and rows the caller
        // never asked to delete are gone for good.
        //
        // The engine here is hand-built ON PURPOSE: it is the unprotected path
        // that needed the guard.
        $adapter = $this->orm->getAdapter();
        $engine = new AggregateWriteEngine($adapter, new ResourceModelHydrator());

        // Writer A moves the row to version 2 — done in SQL on purpose. Going
        // through update() would ALSO replace the owned children (the domain
        // model carries none, and replacing is what update means), which is
        // existing behaviour and would destroy the very row this test is about
        // before the delete is even attempted.
        $adapter->execute("UPDATE ol_notes SET body = 'from A', version = 2 WHERE id = 'n1'");

        $before = $adapter->query('SELECT body, version FROM ol_notes')->rows;

        // Writer B still holds version 1 and asks for a delete.
        try {
            $engine->delete(new OlNoteDomain('n1', 'from B', 1), OlNoteFixture::class, $this->registry());
            self::fail('A stale-version delete must throw StaleAggregateException.');
        } catch (StaleAggregateException) {
            // expected
        }

        self::assertSame(
            $before,
            $adapter->query('SELECT body, version FROM ol_notes')->rows,
            'a refused delete must leave the root exactly as it was',
        );
        self::assertCount(
            1,
            $adapter->query('SELECT id FROM ol_comments')->rows,
            'the owned child must survive a delete that was refused',
        );
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
    use \Semitexa\Orm\Metadata\HasColumnReferences;
    use \Semitexa\Orm\Metadata\HasRelationReferences;

    /** @param list<OlCommentFixture> $comments */
    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,

        #[Column(type: MySqlType::Varchar, length: 255)]
        public string $body,

        #[Version]
        #[Column(type: MySqlType::Int)]
        public int $version,

        // OWNED, so a delete cascades to it — which is what makes a lost child
        // observable when a stale root is refused half way through.
        #[\Semitexa\Orm\Attribute\HasMany(
            target: OlCommentFixture::class,
            foreignKey: 'noteId',
            writePolicy: \Semitexa\Orm\Domain\Enum\RelationWritePolicy::CascadeOwned,
        )]
        public array $comments = [],
    ) {}
}

#[FromTable(name: 'ol_comments')]
final readonly class OlCommentFixture
{
    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,

        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $noteId,

        #[Column(type: MySqlType::Varchar, length: 255)]
        public string $body,
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

#[FromTable(name: 'ol_notes')]
final class OlMutableNoteFixture
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

#[AsMapper(resourceModel: OlMutableNoteFixture::class, domainModel: OlNoteDomain::class)]
final class OlMutableNoteMapper implements \Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof OlMutableNoteFixture || throw new \InvalidArgumentException('Unexpected resource model.');

        return new OlNoteDomain($resourceModel->id, $resourceModel->body, $resourceModel->version);
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof OlNoteDomain || throw new \InvalidArgumentException('Unexpected domain model.');

        return new OlMutableNoteFixture($domainModel->id, $domainModel->body, $domainModel->version);
    }
}

<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Persistence;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Orm\Application\Service\Mapping\MapperRegistry;
use Semitexa\Orm\Application\Service\Persistence\ReplicatedTableRegistration;
use Semitexa\Orm\Application\Service\Persistence\ReplicatedWriteGuard;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\Exception\ReplicatedTableWriteException;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Tests\Fixture\Metadata\ReplicatedNoteResourceModel;
use Semitexa\Orm\Tests\Fixture\Persistence\ReplicatedNote;
use Semitexa\Orm\Tests\Fixture\Persistence\ReplicatedNoteMapper;

/**
 * A #[Replicated] table is written only by the ORM write engine (which
 * captures the change) and the replication applier; any other write would
 * change the row on this node alone.
 */
final class ReplicatedWriteGuardTest extends TestCase
{
    private OrmManager $orm;

    protected function setUp(): void
    {
        ReplicatedWriteGuard::reset();
        $this->orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $this->orm->getAdapter()->execute('CREATE TABLE replicated_notes (id TEXT PRIMARY KEY, title TEXT, body TEXT)');
        $this->orm->getAdapter()->execute('CREATE TABLE other_notes (id TEXT PRIMARY KEY)');
        ReplicatedWriteGuard::register('replicated_notes');
    }

    protected function tearDown(): void
    {
        ReplicatedWriteGuard::reset();
    }

    /** @return iterable<string, array{string}> */
    public static function writes(): iterable
    {
        yield 'insert'               => ["INSERT INTO replicated_notes (id, title, body) VALUES ('1', 't', 'b')"];
        yield 'insert, backticks'    => ["INSERT INTO `replicated_notes` (id) VALUES ('1')"];
        yield 'insert ignore'        => ["INSERT IGNORE INTO replicated_notes (id) VALUES ('1')"];
        yield 'replace'              => ["REPLACE INTO replicated_notes (id) VALUES ('1')"];
        yield 'update, lower case'   => ["update replicated_notes set title = 'x'"];
        yield 'update, quoted'       => ['UPDATE "replicated_notes" SET title = \'x\''];
        yield 'update, schema'       => ["UPDATE `app`.`replicated_notes` SET title = 'x'"];
        yield 'delete'               => ['DELETE FROM replicated_notes'];
        yield 'delete, leading space'=> ["\n   DELETE FROM `replicated_notes` WHERE id = '1'"];
        yield 'truncate'             => ['TRUNCATE TABLE replicated_notes'];
        yield 'table name, other case' => ['DELETE FROM Replicated_Notes'];
    }

    #[Test]
    #[DataProvider('writes')]
    public function a_raw_write_to_a_replicated_table_is_refused(string $sql): void
    {
        try {
            ReplicatedWriteGuard::check($sql);
            self::fail("must refuse: {$sql}");
        } catch (ReplicatedTableWriteException $e) {
            self::assertStringContainsString('is #[Replicated]: write it through the ORM', $e->getMessage());
        }
    }

    #[Test]
    public function the_refusal_reaches_callers_through_the_adapter_and_nothing_is_written(): void
    {
        $this->expectException(ReplicatedTableWriteException::class);

        try {
            $this->orm->getAdapter()->execute("INSERT INTO replicated_notes (id, title, body) VALUES ('1', 't', 'b')");
        } finally {
            ReplicatedWriteGuard::reset();
            self::assertSame(0, (int) $this->orm->getAdapter()->query('SELECT COUNT(*) AS c FROM replicated_notes')->rows[0]['c']);
        }
    }

    #[Test]
    public function reads_ddl_and_other_tables_are_left_alone(): void
    {
        $adapter = $this->orm->getAdapter();
        $adapter->execute('SELECT * FROM replicated_notes');
        $adapter->execute('CREATE INDEX idx_title ON replicated_notes (title)');
        $adapter->execute("INSERT INTO other_notes (id) VALUES ('1')");
        $adapter->execute("UPDATE other_notes SET id = '2' WHERE id IN (SELECT id FROM replicated_notes)"); // reads it, writes another

        self::assertSame([['id' => '1']], $adapter->query('SELECT id FROM other_notes')->rows);
    }

    #[Test]
    public function the_write_engine_writes_a_replicated_table(): void
    {
        $mappers = new MapperRegistry();
        $mappers->build(mapperClasses: [ReplicatedNoteMapper::class], domainModelClasses: [ReplicatedNote::class]);
        $engine = $this->orm->getAggregateWriteEngine();

        $note = $engine->insert(new ReplicatedNote('', 't', 'b'), ReplicatedNoteResourceModel::class, $mappers);
        self::assertInstanceOf(ReplicatedNote::class, $note);
        $engine->update(new ReplicatedNote($note->id, 't', 'edited'), ReplicatedNoteResourceModel::class, $mappers);
        $engine->delete(new ReplicatedNote($note->id, 't', 'edited'), ReplicatedNoteResourceModel::class, $mappers);

        self::assertSame(0, (int) $this->orm->getAdapter()->query('SELECT COUNT(*) AS c FROM replicated_notes')->rows[0]['c']);
    }

    #[Test]
    public function a_permit_nests_and_ends_even_when_the_write_throws(): void
    {
        ReplicatedWriteGuard::permit(static function (): void {
            ReplicatedWriteGuard::permit(static fn () => ReplicatedWriteGuard::check('DELETE FROM replicated_notes'));
            ReplicatedWriteGuard::check('DELETE FROM replicated_notes'); // still inside the outer permit
        });

        try {
            ReplicatedWriteGuard::permit(static function (): never {
                throw new \DomainException('write failed');
            });
        } catch (\DomainException) {
        }

        $this->expectException(ReplicatedTableWriteException::class);
        ReplicatedWriteGuard::check('DELETE FROM replicated_notes');
    }

    #[Test]
    public function registration_takes_exactly_the_replicated_resources_from_discovery(): void
    {
        ReplicatedWriteGuard::reset();
        $discovery = $this->createStub(ClassDiscovery::class);
        // Discovery lists what carries #[Replicated]; a resource without it is never asked about.
        $discovery->method('findClassesWithAttribute')->willReturn([ReplicatedNoteResourceModel::class]);

        ReplicatedTableRegistration::fromDiscovery($discovery);

        self::assertSame(['replicated_notes'], ReplicatedWriteGuard::registered());
        ReplicatedWriteGuard::check("INSERT INTO categories (id, name) VALUES ('1', 'x')"); // not replicated: allowed
    }
}

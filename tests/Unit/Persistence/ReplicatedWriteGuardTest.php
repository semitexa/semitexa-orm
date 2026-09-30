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
use Semitexa\Orm\Tests\Fixture\Metadata\ArchivedReplicatedNoteResourceModel;
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

    /** @var list<string> the process-global registry as it was before this test */
    private array $registeredBefore = [];

    protected function setUp(): void
    {
        $this->registeredBefore = ReplicatedWriteGuard::registered();
        ReplicatedWriteGuard::reset();
        $this->orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $this->orm->getAdapter()->execute('CREATE TABLE replicated_notes (id TEXT PRIMARY KEY, title TEXT, body TEXT)');
        $this->orm->getAdapter()->execute('CREATE TABLE other_notes (id TEXT PRIMARY KEY)');
        ReplicatedWriteGuard::register('replicated_notes');
    }

    protected function tearDown(): void
    {
        ReplicatedWriteGuard::reset();
        foreach ($this->registeredBefore as $table) {
            ReplicatedWriteGuard::register($table);
        }
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
        yield 'sqlite insert or replace' => ["INSERT OR REPLACE INTO replicated_notes (id) VALUES ('1')"];
        yield 'sqlite update or ignore'  => ["UPDATE OR IGNORE replicated_notes SET title = 'x'"];
        yield 'leading block comment'    => ["/* nightly fix */ UPDATE replicated_notes SET title = 'x'"];
        yield 'leading line comments'    => ["-- fix\n# again\nDELETE FROM replicated_notes"];
        yield 'cte then update'          => ["WITH stale AS (SELECT id FROM other_notes) UPDATE replicated_notes SET title = 'x' WHERE id IN (SELECT id FROM stale)"];
        yield 'comment between the ctes and the write' => ["WITH s AS (SELECT id FROM other_notes) /* fix */ UPDATE replicated_notes SET title = 'x'"];
        yield 'a ) inside a comment in a cte body'     => ["WITH x AS (SELECT 1 /* ) */) DELETE FROM replicated_notes"];
        yield 'comments between cte tokens'            => ["WITH /* a */ x /* b */ AS /* c */ (SELECT 1 -- )\n) -- d\nINSERT INTO replicated_notes (id) VALUES ('1')"];
        yield 'recursive ctes then insert' => ["WITH RECURSIVE n (i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 3), m AS MATERIALIZED (SELECT i FROM n) INSERT INTO replicated_notes (id) SELECT i FROM m"];
        yield 'mysql modifiers, delete'  => ['DELETE LOW_PRIORITY QUICK IGNORE FROM replicated_notes'];
        yield 'mysql modifiers, insert'  => ["INSERT LOW_PRIORITY IGNORE INTO replicated_notes (id) VALUES ('1')"];
        yield 'multi-table delete'       => ['DELETE n FROM replicated_notes n JOIN other_notes o ON o.id = n.id'];
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

    /** @return iterable<string, array{string}> */
    public static function notWrites(): iterable
    {
        yield 'cte then select'            => ['WITH x AS (SELECT id FROM replicated_notes) SELECT * FROM x'];
        yield 'cte calling REPLACE()'      => ["WITH x AS (SELECT REPLACE(title, 'a', 'b') AS t FROM replicated_notes) SELECT * FROM x"];
        yield 'cte aliasing update/delete' => ['WITH x AS (SELECT title AS `update`, body AS `delete` FROM replicated_notes) SELECT * FROM x'];
        yield 'cte with comments, then select' => ["WITH x /* ) */ AS (SELECT 1 /* UPDATE replicated_notes */) SELECT * FROM x"];
        yield 'two ctes, a paren in a string' => ["WITH a AS (SELECT ')' AS p FROM replicated_notes), b (n) AS (SELECT 1) SELECT * FROM a, b"];
        yield 'a select that says update'  => ["SELECT 'UPDATE replicated_notes' AS note"];
        yield 'another table, with modifiers' => ['DELETE LOW_PRIORITY FROM other_notes'];
        yield 'another table after a comment' => ["/* c */ INSERT OR REPLACE INTO other_notes (id) VALUES ('1')"];
    }

    #[Test]
    #[DataProvider('notWrites')]
    public function statements_that_do_not_write_a_replicated_table_pass(string $sql): void
    {
        ReplicatedWriteGuard::check($sql);
        $this->addToAssertionCount(1); // reaching here is the assertion: check() did not throw
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
        self::assertSame([['title' => 't', 'body' => 'b']], $this->orm->getAdapter()->query('SELECT title, body FROM replicated_notes')->rows);

        $engine->update(new ReplicatedNote($note->id, 't', 'edited'), ReplicatedNoteResourceModel::class, $mappers);
        self::assertSame([['title' => 't', 'body' => 'edited']], $this->orm->getAdapter()->query('SELECT title, body FROM replicated_notes')->rows);

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

    #[Test]
    public function a_table_of_the_same_name_on_another_connection_is_not_refused(): void
    {
        // replicated_notes is registered on 'default'; 'archive' is another
        // database whose own replicated_notes nothing replicates.
        ReplicatedWriteGuard::check("UPDATE replicated_notes SET title = 'x'", 'archive');

        $this->expectException(ReplicatedTableWriteException::class);
        ReplicatedWriteGuard::check("UPDATE replicated_notes SET title = 'x'");
    }

    #[Test]
    public function a_registration_takes_the_connection_its_resource_declares(): void
    {
        ReplicatedWriteGuard::reset();
        $discovery = $this->createStub(ClassDiscovery::class);
        $discovery->method('findClassesWithAttribute')->willReturn([ArchivedReplicatedNoteResourceModel::class]);

        ReplicatedTableRegistration::fromDiscovery($discovery);

        self::assertSame(['replicated_notes'], ReplicatedWriteGuard::registered('archive'));
        self::assertSame([], ReplicatedWriteGuard::registered());
    }

    #[Test]
    public function each_managers_adapters_are_judged_against_their_own_connection(): void
    {
        // The name travels from the manager into its adapter and into the
        // single-connection adapter a transaction runs on.
        $archive = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true), connectionName: 'archive');
        $archive->getAdapter()->execute('CREATE TABLE replicated_notes (id TEXT PRIMARY KEY, title TEXT)');

        $archive->getAdapter()->execute("INSERT INTO replicated_notes (id, title) VALUES ('1', 'kept')");
        $archive->getTransactionManager()->run(static function ($db): void {
            $db->execute("UPDATE replicated_notes SET title = 'moved' WHERE id = '1'");
        });
        self::assertSame('moved', $archive->getAdapter()->execute('SELECT title FROM replicated_notes')->rows[0]['title'] ?? null);

        ReplicatedWriteGuard::register('replicated_notes', 'archive');
        try {
            $archive->getTransactionManager()->run(static function ($db): void {
                $db->execute("UPDATE replicated_notes SET title = 'raw' WHERE id = '1'");
            });
            self::fail('a replicated table on its own connection is still guarded');
        } catch (ReplicatedTableWriteException) {
        } finally {
            $archive->shutdown();
        }
    }
}

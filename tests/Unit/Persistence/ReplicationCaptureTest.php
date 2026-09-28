<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Persistence;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Application\Service\Mapping\MapperRegistry;
use Semitexa\Orm\Application\Service\Persistence\ReplicationCapture;
use Semitexa\Orm\Domain\Contract\ReplicationCaptureInterface;
use Semitexa\Orm\Domain\Enum\ResourceChangeOperation;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\Domain\Model\RowChange;
use Semitexa\Orm\Exception\InvalidRelationDeclarationException;
use Semitexa\Orm\Exception\InvalidResourceModelException;
use Semitexa\Orm\Metadata\ResourceModelMetadataRegistry;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Tests\Fixture\Metadata\InvalidCascadeToReplicatedResourceModel;
use Semitexa\Orm\Tests\Fixture\Metadata\InvalidReplicatedKeyResourceModel;
use Semitexa\Orm\Tests\Fixture\Metadata\ReplicatedNoteResourceModel;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidCategoryResourceModel;
use Semitexa\Orm\Tests\Fixture\Persistence\PersistableCategoryDomainModel;
use Semitexa\Orm\Tests\Fixture\Persistence\PersistableCategoryMapper;
use Semitexa\Orm\Tests\Fixture\Persistence\ReplicatedNote;
use Semitexa\Orm\Tests\Fixture\Persistence\ReplicatedNoteMapper;

/**
 * A write to a #[Replicated] row reaches the registered capture inside the
 * write's own transaction, with the row as stored before and after — and
 * whatever the capture writes commits or rolls back with the data.
 */
final class ReplicationCaptureTest extends TestCase
{
    private OrmManager $orm;
    private RecordingCapture $capture;

    protected function setUp(): void
    {
        $this->orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $this->orm->getAdapter()->execute('CREATE TABLE replicated_notes (id TEXT PRIMARY KEY, title TEXT, body TEXT)');
        $this->orm->getAdapter()->execute('CREATE TABLE capture_log (id INTEGER PRIMARY KEY AUTOINCREMENT, op TEXT)');

        $this->capture = new RecordingCapture();
        ReplicationCapture::setResolver(fn (): ReplicationCaptureInterface => $this->capture);
    }

    protected function tearDown(): void
    {
        ReplicationCapture::setResolver(null);
    }

    #[Test]
    public function insert_update_and_delete_each_hand_the_capture_the_row_before_and_after(): void
    {
        $engine = $this->orm->getAggregateWriteEngine();

        $note = $engine->insert(new ReplicatedNote('', 'first', 'hello'), ReplicatedNoteResourceModel::class, $this->registry());
        self::assertInstanceOf(ReplicatedNote::class, $note);
        $engine->update(new ReplicatedNote($note->id, 'first', 'edited'), ReplicatedNoteResourceModel::class, $this->registry());
        $engine->delete(new ReplicatedNote($note->id, 'first', 'edited'), ReplicatedNoteResourceModel::class, $this->registry());

        [$insert, $update, $delete] = $this->capture->changes;

        self::assertSame(ResourceChangeOperation::Insert, $insert->operation);
        self::assertNull($insert->before);
        self::assertSame(['id' => $note->id, 'title' => 'first', 'body' => 'hello'], $insert->after);
        self::assertSame($note->id, $insert->primaryKeyValue);
        self::assertSame('replicated_notes', $insert->tableName);

        self::assertSame(ResourceChangeOperation::Update, $update->operation);
        self::assertSame('hello', $update->before['body'] ?? null);
        self::assertSame('edited', $update->after['body'] ?? null);

        self::assertSame(ResourceChangeOperation::Delete, $delete->operation);
        self::assertSame('edited', $delete->before['body'] ?? null);
        self::assertNull($delete->after, 'a removed row has no after-image');
    }

    #[Test]
    public function what_the_capture_writes_commits_with_the_data(): void
    {
        $this->orm->getAggregateWriteEngine()->insert(new ReplicatedNote('', 't', 'b'), ReplicatedNoteResourceModel::class, $this->registry());

        self::assertSame(1, $this->rowsIn('capture_log'));
    }

    #[Test]
    public function a_capture_that_throws_rolls_the_write_back(): void
    {
        $this->capture->failWith = new \RuntimeException('outbox unavailable');

        try {
            $this->orm->getAggregateWriteEngine()->insert(new ReplicatedNote('', 't', 'b'), ReplicatedNoteResourceModel::class, $this->registry());
            self::fail('a failed capture must fail the write');
        } catch (\RuntimeException $e) {
            self::assertSame('outbox unavailable', $e->getMessage());
        }

        self::assertSame(0, $this->rowsIn('replicated_notes'), 'a write the capture could not record must not commit');
        self::assertSame(0, $this->rowsIn('capture_log'));
    }

    #[Test]
    public function an_update_of_a_row_that_is_not_there_captures_nothing(): void
    {
        $this->orm->getAggregateWriteEngine()->update(new ReplicatedNote('00000000-0000-7000-8000-000000000000', 't', 'b'), ReplicatedNoteResourceModel::class, $this->registry());

        self::assertSame([], $this->capture->changes);
    }

    #[Test]
    public function resources_without_the_attribute_are_not_captured(): void
    {
        $this->orm->getAdapter()->execute('CREATE TABLE categories (id TEXT PRIMARY KEY, name TEXT)');
        $registry = new MapperRegistry();
        $registry->build(mapperClasses: [PersistableCategoryMapper::class], domainModelClasses: [PersistableCategoryDomainModel::class]);

        $this->orm->getAggregateWriteEngine()->insert(new PersistableCategoryDomainModel('', 'plain'), ValidCategoryResourceModel::class, $registry);

        self::assertSame([], $this->capture->changes);
    }

    #[Test]
    public function without_a_registered_capture_replicated_resources_are_written_normally(): void
    {
        ReplicationCapture::setResolver(null);

        $this->orm->getAggregateWriteEngine()->insert(new ReplicatedNote('', 't', 'b'), ReplicatedNoteResourceModel::class, $this->registry());

        self::assertSame(1, $this->rowsIn('replicated_notes'));
        self::assertSame([], $this->capture->changes);
    }

    #[Test]
    public function a_replicated_resource_with_an_auto_increment_key_is_refused(): void
    {
        $this->expectException(InvalidResourceModelException::class);
        $this->expectExceptionMessage("strategy: 'uuid'");

        (new ResourceModelMetadataRegistry())->for(InvalidReplicatedKeyResourceModel::class);
    }

    #[Test]
    public function a_cascade_owned_relation_to_a_replicated_resource_is_refused(): void
    {
        $this->expectException(InvalidRelationDeclarationException::class);
        $this->expectExceptionMessage('$notes is CascadeOwned but its target');

        (new ResourceModelMetadataRegistry())->for(InvalidCascadeToReplicatedResourceModel::class);
    }

    #[Test]
    public function a_caller_supplied_key_that_is_not_a_uuidv7_is_refused_and_nothing_is_written(): void
    {
        try {
            $this->orm->getAggregateWriteEngine()->insert(
                new ReplicatedNote('6f1c2a44-8e0b-4c3d-9f6a-2b7d1e0c5a91', 't', 'b'), // a UUIDv4
                ReplicatedNoteResourceModel::class,
                $this->registry(),
            );
            self::fail('a non-UUIDv7 key on a replicated row must be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString("needs a UUIDv7 primary key; got '6f1c2a44-8e0b-4c3d-9f6a-2b7d1e0c5a91'", $e->getMessage());
        }

        self::assertSame(0, $this->rowsIn('replicated_notes'));
    }

    #[Test]
    public function uuidv7_is_recognised_as_text_and_as_raw_bytes(): void
    {
        $v7 = '01a0e7a0-0000-7000-8000-000000000001';

        self::assertTrue(ReplicationCapture::isUuidV7($v7));
        self::assertTrue(ReplicationCapture::isUuidV7(hex2bin(str_replace('-', '', $v7))));
        self::assertFalse(ReplicationCapture::isUuidV7('6f1c2a44-8e0b-4c3d-9f6a-2b7d1e0c5a91'));
        self::assertFalse(ReplicationCapture::isUuidV7('42'));
    }

    private function rowsIn(string $table): int
    {
        return (int) $this->orm->getAdapter()->query("SELECT COUNT(*) AS c FROM {$table}")->rows[0]['c'];
    }

    private function registry(): MapperRegistry
    {
        $registry = new MapperRegistry();
        $registry->build(mapperClasses: [ReplicatedNoteMapper::class], domainModelClasses: [ReplicatedNote::class]);

        return $registry;
    }
}

final class RecordingCapture implements ReplicationCaptureInterface
{
    /** @var list<RowChange> */
    public array $changes = [];

    public ?\Throwable $failWith = null;

    public function capture(RowChange $change, DatabaseAdapterInterface $transaction): void
    {
        // Written through the transaction's adapter — it must share the data's fate.
        $transaction->execute('INSERT INTO capture_log (op) VALUES (:op)', ['op' => $change->operation->value]);

        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        $this->changes[] = $change;
    }
}

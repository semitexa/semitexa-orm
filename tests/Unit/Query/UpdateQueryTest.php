<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\UpdateQuery;
use Semitexa\Orm\Tests\Fixture\Hydration\FakeDatabaseAdapter;

/**
 * UpdateQuery must apply the same identifier escaping as WhereTrait and
 * DeleteQuery: identifiers are metadata-derived today, but the builder must
 * not trust its caller for that. The happy paths run against a real in-memory
 * SQLite driver so the generated SQL is proven to execute; the escaping pins
 * inspect the produced SQL through a recording fake adapter.
 */
final class UpdateQueryTest extends TestCase
{
    private DatabaseAdapterInterface $adapter;

    protected function setUp(): void
    {
        $this->adapter = (new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true)))->getAdapter();
        $this->adapter->execute('CREATE TABLE widget (id INTEGER PRIMARY KEY, owner_id TEXT NOT NULL, label TEXT NOT NULL)');
        foreach ([
            [1, 'alice', 'first'],
            [2, 'alice', 'second'],
            [3, 'bob', 'third'],
        ] as [$id, $owner, $label]) {
            $this->adapter->execute(
                'INSERT INTO widget (id, owner_id, label) VALUES (:id, :owner, :label)',
                ['id' => $id, 'owner' => $owner, 'label' => $label],
            );
        }
    }

    /** @return list<array{id: int, owner_id: string, label: string}> */
    private function rows(): array
    {
        /** @var list<array{id: int, owner_id: string, label: string}> */
        return $this->adapter->query('SELECT id, owner_id, label FROM widget ORDER BY id')->rows;
    }

    #[Test]
    public function execute_updates_only_the_row_matching_the_primary_key(): void
    {
        (new UpdateQuery('widget', $this->adapter))->execute(['id' => 2, 'label' => 'renamed'], 'id');

        $labels = array_column($this->rows(), 'label', 'id');
        self::assertSame(['first', 'renamed', 'third'], array_values($labels));
    }

    #[Test]
    public function execute_where_updates_every_matching_row(): void
    {
        (new UpdateQuery('widget', $this->adapter))
            ->where('owner_id', '=', 'alice')
            ->executeWhere(['label' => 'owned']);

        $labels = array_column($this->rows(), 'label', 'id');
        self::assertSame(['owned', 'owned', 'third'], array_values($labels));
    }

    #[Test]
    public function execute_where_without_conditions_is_refused(): void
    {
        $this->expectException(\LogicException::class);

        (new UpdateQuery('widget', $this->adapter))->executeWhere(['label' => 'all']);
    }

    #[Test]
    public function execute_escapes_backticks_in_table_pk_and_set_identifiers(): void
    {
        $fake = new FakeDatabaseAdapter([]);

        (new UpdateQuery('wid`get', $fake))->execute(['i`d' => 1, 'la`bel' => 'x'], 'i`d');

        self::assertCount(1, $fake->executed);
        $sql = $fake->executed[0]['sql'];
        self::assertStringContainsString('UPDATE `wid``get` SET', $sql, 'table identifier not escaped');
        self::assertStringContainsString('`la``bel` =', $sql, 'SET column identifier not escaped');
        self::assertStringContainsString('WHERE `i``d` = :pk_value', $sql, 'PK identifier not escaped');
        self::assertStringNotContainsString('`la`bel`', $sql, 'a raw backtick survived into the SQL');
    }

    #[Test]
    public function execute_where_escapes_backticks_in_table_and_set_identifiers(): void
    {
        $fake = new FakeDatabaseAdapter([]);

        (new UpdateQuery('wid`get', $fake))
            ->where('owner_id', '=', 'alice')
            ->executeWhere(['la`bel' => 'x']);

        self::assertCount(1, $fake->executed);
        $sql = $fake->executed[0]['sql'];
        self::assertStringContainsString('UPDATE `wid``get` SET', $sql, 'table identifier not escaped');
        self::assertStringContainsString('`la``bel` =', $sql, 'SET column identifier not escaped');
    }
}

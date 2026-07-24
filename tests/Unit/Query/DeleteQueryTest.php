<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\DeleteQuery;

/**
 * DeleteQuery is the single delete path used by AggregateWriteEngine — row
 * deletes, cascade-owned relation cleanup and pivot resync all go through it
 * instead of hand-built DELETE strings. Exercised against a real in-memory
 * SQLite driver so the generated SQL is proven to execute, not just to look
 * right.
 */
final class DeleteQueryTest extends TestCase
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

    /** @return list<int> */
    private function remainingIds(): array
    {
        $rows = $this->adapter->query('SELECT id FROM widget ORDER BY id')->rows;

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    #[Test]
    public function execute_deletes_the_single_row_matching_the_column(): void
    {
        (new DeleteQuery('widget', $this->adapter))->execute('id', 2);

        self::assertSame([1, 3], $this->remainingIds());
    }

    #[Test]
    public function execute_is_not_limited_to_primary_keys_and_deletes_every_match(): void
    {
        // The cascade-owned and pivot paths call execute() with a foreign key,
        // so a non-unique column must delete the whole matching set.
        (new DeleteQuery('widget', $this->adapter))->execute('owner_id', 'alice');

        self::assertSame([3], $this->remainingIds());
    }

    #[Test]
    public function execute_narrows_further_when_conditions_were_already_staged(): void
    {
        (new DeleteQuery('widget', $this->adapter))
            ->where('label', '=', 'second')
            ->execute('owner_id', 'alice');

        self::assertSame([1, 3], $this->remainingIds());
    }

    #[Test]
    public function repeated_execute_calls_on_one_instance_stay_independent(): void
    {
        // execute() stages an equality condition; if it kept it, the second
        // call would read WHERE owner_id = 'alice' AND owner_id = 'bob' and
        // delete nothing.
        $query = new DeleteQuery('widget', $this->adapter);
        $query->execute('owner_id', 'alice');
        $query->execute('owner_id', 'bob');

        self::assertSame([], $this->remainingIds());
    }

    #[Test]
    public function execute_where_applies_the_staged_conditions(): void
    {
        (new DeleteQuery('widget', $this->adapter))
            ->whereIn('id', [1, 3])
            ->executeWhere();

        self::assertSame([2], $this->remainingIds());
    }

    #[Test]
    public function execute_where_refuses_to_delete_the_whole_table(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('requires at least one WHERE condition');

        (new DeleteQuery('widget', $this->adapter))->executeWhere();
    }

    #[Test]
    public function execute_where_leaves_the_table_untouched_when_it_refuses(): void
    {
        try {
            (new DeleteQuery('widget', $this->adapter))->executeWhere();
        } catch (\LogicException) {
            // expected — the guard must fire before any statement is issued
        }

        self::assertSame([1, 2, 3], $this->remainingIds());
    }

    #[Test]
    public function a_backticked_column_cannot_break_out_of_its_identifier(): void
    {
        // execute() used to interpolate the column straight into the SQL while
        // where() escaped it; both paths now share the escaping. Unescaped this
        // reads as `id` OR `1` = :p — an always-true predicate that empties the
        // table. Escaped it is one nonsense identifier and the statement fails.
        $threw = false;
        try {
            (new DeleteQuery('widget', $this->adapter))->execute('id` OR `1', 1);
        } catch (\Throwable) {
            $threw = true;
        }

        self::assertTrue($threw, 'A broken-out identifier must not produce a runnable statement.');
        self::assertSame([1, 2, 3], $this->remainingIds(), 'No row may be deleted by an injected predicate.');
    }

    #[Test]
    public function a_backticked_table_cannot_break_out_of_its_identifier(): void
    {
        $threw = false;
        try {
            (new DeleteQuery('widget` WHERE 1=1 --', $this->adapter))->execute('id', 1);
        } catch (\Throwable) {
            $threw = true;
        }

        self::assertTrue($threw, 'A broken-out table name must not produce a runnable statement.');
        self::assertSame([1, 2, 3], $this->remainingIds(), 'No row may be deleted through the table identifier.');
    }
}

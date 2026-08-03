<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Application\Service\Schema\AbstractSchemaComparator;
use Semitexa\Orm\Domain\Model\ColumnDefinition;
use Semitexa\Orm\Domain\Model\DbColumnState;
use Semitexa\Orm\Domain\Model\DbIndexState;
use Semitexa\Orm\Domain\Model\IndexDefinition;
use Semitexa\Orm\Domain\Model\SchemaDiff;

/**
 * Tests for the dialect-agnostic half of schema comparison.
 *
 * ep-duplication-sweep lifted three byte-identical methods out of
 * {@see \Semitexa\Orm\Application\Service\Schema\SchemaComparator} and
 * {@see \Semitexa\Orm\Application\Service\Schema\SqliteSchemaComparator} into
 * {@see AbstractSchemaComparator}. Only the SQLite side had any tests, so the
 * shared logic was reachable through exactly one dialect; these cover it
 * directly, for both.
 *
 * The FK guard below is not hypothetical. Moving `compareIndexes()` to the base
 * while `$fkIndexNames` stayed `private` on each subclass produced a silent
 * failure: PHP answers `isset()` on a parent's view of a child's private
 * property with `false` and raises nothing, so the guard would have kept
 * "working" while protecting nothing — and the first migration to drop an
 * FK-backing index would have died with MySQL error 1553. The SQLite suite
 * stayed green throughout. This test is what makes that regression loud.
 */
final class AbstractSchemaComparatorTest extends TestCase
{
    #[Test]
    public function an_index_backing_a_foreign_key_is_never_dropped(): void
    {
        $comparator = new FkAwareComparatorStub(['orders.fk_orders_customer' => true]);
        $diff = new SchemaDiff();

        // The DB has an index the code does not declare. Normally that is a DROP —
        // but a foreign key depends on this one.
        $comparator->runCompareIndexes(
            'orders',
            [],
            [new DbIndexState('fk_orders_customer', ['customer_id'], false)],
            $diff,
        );

        self::assertSame(
            [],
            $diff->getDropIndexes(),
            'an FK-backing index was dropped; the live migration would fail with MySQL error 1553',
        );
    }

    #[Test]
    public function an_unreferenced_db_index_is_still_dropped(): void
    {
        // The counterpart: the guard must protect FK indexes only, not freeze
        // every index the code stopped declaring.
        $comparator = new FkAwareComparatorStub([]);
        $diff = new SchemaDiff();

        $comparator->runCompareIndexes(
            'orders',
            [],
            [new DbIndexState('idx_orders_stale', ['legacy_col'], false)],
            $diff,
        );

        self::assertNotSame([], $diff->getDropIndexes(), 'a genuinely orphaned index should be dropped');
    }

    #[Test]
    public function an_unnamed_index_gets_a_deterministic_generated_name(): void
    {
        // Migrations are re-run and diffed; a name that varied between runs would
        // make every comparison look like a change.
        $comparator = new FkAwareComparatorStub([]);

        self::assertSame(
            'idx_orders_customer_id_status',
            $comparator->runGenerateIndexName('orders', ['customer_id', 'status'], false),
        );
        self::assertSame(
            'uniq_orders_email',
            $comparator->runGenerateIndexName('orders', ['email'], true),
        );
    }

    #[Test]
    public function an_index_matching_by_structure_under_another_name_is_renamed_not_duplicated(): void
    {
        // The DB grew this index under an auto-generated name; the code now names
        // it. Same columns, same uniqueness — so it is a rename, and adding a
        // second identical index would be the wrong answer.
        $comparator = new FkAwareComparatorStub([]);
        $diff = new SchemaDiff();

        $comparator->runCompareIndexes(
            'orders',
            [new IndexDefinition(['customer_id'], false, 'idx_orders_by_customer')],
            [new DbIndexState('orders_customer_id_idx', ['customer_id'], false)],
            $diff,
        );

        self::assertCount(1, $diff->getDropIndexes(), 'the old name is dropped');
        self::assertCount(1, $diff->getAddIndexes(), 'the new name is added');
    }

    #[Test]
    public function an_index_that_already_matches_produces_no_change(): void
    {
        $comparator = new FkAwareComparatorStub([]);
        $diff = new SchemaDiff();

        $comparator->runCompareIndexes(
            'orders',
            [new IndexDefinition(['customer_id'], false, 'idx_orders_by_customer')],
            [new DbIndexState('idx_orders_by_customer', ['customer_id'], false)],
            $diff,
        );

        self::assertSame([], $diff->getDropIndexes());
        self::assertSame([], $diff->getAddIndexes());
    }
}

/**
 * Minimal concrete comparator: supplies the one abstract hook and exposes the
 * protected shared methods, so the base can be exercised without a database.
 */
final class FkAwareComparatorStub extends AbstractSchemaComparator
{
    /**
     * @param array<string, bool> $fkIndexNames
     */
    public function __construct(array $fkIndexNames)
    {
        $this->fkIndexNames = $fkIndexNames;
    }

    /**
     * @param IndexDefinition[] $codeIndexes
     * @param DbIndexState[]    $dbIndexes
     */
    public function runCompareIndexes(string $table, array $codeIndexes, array $dbIndexes, SchemaDiff $diff): void
    {
        $this->compareIndexes($table, $codeIndexes, $dbIndexes, $diff);
    }

    /**
     * @param string[] $columns
     */
    public function runGenerateIndexName(string $table, array $columns, bool $unique): string
    {
        return $this->generateIndexName($table, $columns, $unique);
    }

    public function compare(array $codeSchema): SchemaDiff
    {
        return new SchemaDiff();
    }

    protected function compareColumn(ColumnDefinition $code, DbColumnState $db): array
    {
        return [];
    }
}

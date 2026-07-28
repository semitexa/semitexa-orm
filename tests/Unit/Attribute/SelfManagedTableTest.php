<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Attribute;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Semitexa\Orm\Attribute\SelfManagedTable;
use Semitexa\Orm\OrmManager;

/**
 * Ownership declaration for tables that have no `#[FromTable]` resource.
 *
 * Sync detects drops by elimination, so a table no resource claims is assumed
 * abandoned and enters the two-phase drop. That is correct for a leftover and
 * wrong for a table a package creates on purpose — and the failure is silent,
 * because phase 1 only writes a comment. It surfaces when someone finally runs
 * with `--allow-destructive` and the data is gone.
 */
final class SelfManagedTableTest extends TestCase
{
    #[Test]
    public function the_attribute_is_repeatable_on_a_class(): void
    {
        // One repository may own more than one table, and a single class must be
        // able to say so without inventing a second declaration style.
        $attribute = (new ReflectionClass(SelfManagedTable::class))->getAttributes()[0] ?? null;

        self::assertNotNull($attribute);
        $flags = $attribute->getArguments()[0] ?? 0;

        self::assertSame(\Attribute::TARGET_CLASS, $flags & \Attribute::TARGET_CLASS);
        self::assertSame(\Attribute::IS_REPEATABLE, $flags & \Attribute::IS_REPEATABLE);
    }

    #[Test]
    public function it_carries_the_table_name_verbatim(): void
    {
        self::assertSame('platform_update_journal', (new SelfManagedTable('platform_update_journal'))->table);
    }

    #[Test]
    public function ignore_tables_merges_the_env_hatch_with_declared_ownership(): void
    {
        // Two sources, two audiences: ORM_IGNORE_TABLES is for the operator
        // pointing at something outside Semitexa; the attribute is a package
        // declaring a table it maintains itself. Neither may shadow the other.
        // Capture and restore rather than unset: the runner may have supplied a
        // value, and clearing it would silently alter every later test.
        $previous = getenv('ORM_IGNORE_TABLES');
        putenv('ORM_IGNORE_TABLES=legacy_billing, ,external_audit');

        try {
            $resolve = new ReflectionMethod(OrmManager::class, 'resolveIgnoreTables');
            $resolve->setAccessible(true);
            /** @var list<string> $tables */
            $tables = $resolve->invoke(new OrmManager());

            self::assertContains('legacy_billing', $tables, 'the operator hatch still works');
            self::assertContains('external_audit', $tables);
            self::assertNotContains('', $tables, 'a stray empty entry must not become a table name');
            self::assertSame(array_values(array_unique($tables)), $tables, 'no duplicates');
        } finally {
            is_string($previous)
                ? putenv('ORM_IGNORE_TABLES=' . $previous)
                : putenv('ORM_IGNORE_TABLES');
        }
    }

    #[Test]
    public function a_table_named_zero_is_not_swallowed_by_the_filter(): void
    {
        // A bare array_filter() treats "0" as false. Legal table name, pathological
        // but free to guard against.
        $previous = getenv('ORM_IGNORE_TABLES');
        putenv('ORM_IGNORE_TABLES=0,legacy');

        try {
            $resolve = new ReflectionMethod(OrmManager::class, 'resolveIgnoreTables');
            $resolve->setAccessible(true);
            /** @var list<string> $tables */
            $tables = $resolve->invoke(new OrmManager());

            self::assertContains('0', $tables);
            self::assertContains('legacy', $tables);
        } finally {
            is_string($previous)
                ? putenv('ORM_IGNORE_TABLES=' . $previous)
                : putenv('ORM_IGNORE_TABLES');
        }
    }

    #[Test]
    public function the_update_journals_are_protected_from_the_two_phase_drop(): void
    {
        // The motivating case, pinned by name: these two tables record the
        // progress of the very command that may be syncing the schema, so they
        // are created with plain SQL and own no resource. Before this, every
        // sync marked them SEMITEXA_DEPRECATED and the next destructive run
        // would have dropped the update tool's own history.
        $resolve = new ReflectionMethod(OrmManager::class, 'resolveIgnoreTables');
        $resolve->setAccessible(true);
        /** @var list<string> $tables */
        $tables = $resolve->invoke(new OrmManager());

        self::assertContains('platform_update_journal', $tables);
        self::assertContains('platform_update_run_journal', $tables);
    }
}

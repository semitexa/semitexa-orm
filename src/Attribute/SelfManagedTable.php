<?php

declare(strict_types=1);

namespace Semitexa\Orm\Attribute;

use Attribute;

/**
 * Declares a table that its owning package creates and migrates itself, so ORM
 * sync must leave it alone.
 *
 * Sync's drop detection works by elimination: any table in the database that no
 * `#[FromTable]` resource claims is assumed abandoned and enters the two-phase
 * drop — marked `SEMITEXA_DEPRECATED` on one run, dropped on the next run that
 * permits destructive operations. That is right for a table left behind by a
 * deleted resource, and wrong for a table a package owns on purpose.
 *
 * Some tables genuinely cannot be ORM resources. `semitexa/update`'s journals
 * are the motivating case: they record the progress of the very command that
 * may be syncing the ORM schema, so they are created with plain SQL before the
 * ORM is necessarily in a usable state. Declaring them as resources would make
 * the update journal depend on the migration it exists to record.
 *
 * Without this, such a table is silently queued for deletion on every sync —
 * the failure only becomes visible when someone finally runs with
 * `--allow-destructive` and the history disappears.
 *
 * Attach to any discoverable class in the owning package, typically the
 * repository that creates the table:
 *
 * ```php
 * #[SelfManagedTable('platform_update_journal')]
 * final class JournalRepository { ... }
 * ```
 *
 * This is a package-level statement of ownership. The `ORM_IGNORE_TABLES`
 * environment variable remains the operator-level escape hatch for tables that
 * belong to something outside Semitexa entirely; the two are merged.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class SelfManagedTable
{
    public function __construct(
        public readonly string $table,
    ) {
    }
}

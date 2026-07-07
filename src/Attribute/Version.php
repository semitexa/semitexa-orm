<?php

declare(strict_types=1);

namespace Semitexa\Orm\Attribute;

use Attribute;

/**
 * Optimistic-locking version column.
 *
 * Mark exactly one int #[Column] property. Every UPDATE then guards on the
 * version it read (`WHERE pk = :pk AND version = :expected`) and bumps it by
 * one in the same statement; a concurrent writer that got there first makes
 * the guard miss and the write throws StaleAggregateException instead of
 * silently overwriting the newer state (lost update).
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Version
{
}

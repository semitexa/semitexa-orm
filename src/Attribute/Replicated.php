<?php

declare(strict_types=1);

namespace Semitexa\Orm\Attribute;

use Attribute;

/**
 * Rows of this resource are replicated to every node of a multi-master
 * cluster (ADR 0001 in semitexa/ledger).
 *
 * Writes through the ORM capture the row, inside the write's own transaction,
 * for a replication capture to send on. The resource must use a UUIDv7 primary
 * key — an auto-increment id would collide between nodes — and must only be
 * written through the ORM, since nothing else is captured.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Replicated
{
}

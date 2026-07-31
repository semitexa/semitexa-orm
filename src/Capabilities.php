<?php

declare(strict_types=1);

namespace Semitexa\Orm;

use Semitexa\Core\Attribute\Capability;

/**
 * What this package offers, for the capability catalog.
 *
 * Without this the package is invisible to anyone whose project has not
 * installed it - which is precisely the audience worth telling, since they are
 * the ones about to build it by hand. The convention is one `Capabilities` class
 * per package: a definite place to look, and a definite place for a guard to
 * check.
 *
 * Nothing reads this at runtime.
 */
#[Capability(
    id: 'orm.persistence',
    summary: 'Attribute-driven persistence: tables, relations and queries derived from the domain model itself.',
    useWhen: 'Anything outlives the request - entities, relations, or a schema that has to keep up with the code.',
    avoidWhen: 'A single throwaway read against an existing table. A one-line query needs no mapping layer.',
    replaces: [
        'hand-written PDO queries plus a bespoke row-to-object mapper',
        'a migration file written by hand for every column added to a model',
    ],
)]
final class Capabilities
{
}

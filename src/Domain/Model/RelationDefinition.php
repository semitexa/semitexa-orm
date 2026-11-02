<?php

declare(strict_types=1);

namespace Semitexa\Orm\Domain\Model;

use Semitexa\Orm\Domain\Enum\ForeignKeyAction;

/**
 * A relation a resource model declares (#[BelongsTo], #[HasMany], #[OneToOne],
 * #[ManyToMany]) as the schema reads it: what it points at, over which key,
 * and the foreign-key actions it asked for (null: the schema picks them).
 *
 * It was an array whose hand-written shape had lost onDelete and onUpdate,
 * while the code reading it declared them string|null: a real value is a
 * ForeignKeyAction, and the shape hid both facts from every reader.
 */
final readonly class RelationDefinition
{
    public function __construct(
        public string $type,
        public string $target,
        public string $foreignKey,
        public ?ForeignKeyAction $onDelete = null,
        public ?ForeignKeyAction $onUpdate = null,
        public ?string $pivotTable = null,
        public ?string $relatedKey = null,
    ) {}
}

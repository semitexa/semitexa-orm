<?php

declare(strict_types=1);

namespace Semitexa\Orm\Domain\Contract;

/**
 * Translates between one persistence resource model and one domain model.
 *
 * **Who converts what.** The ORM owns COLUMN-TYPE conversion and a mapper must
 * not repeat it. `TypeCaster` runs on every read and every write: a BINARY(16)
 * column arrives as a canonical uuid string and goes back as 16 bytes, a
 * DATETIME arrives as a `DateTimeImmutable`, an enum column as its backed case.
 * A mapper never sees the raw column value and never has to produce one.
 *
 * A mapper owns the storage shapes the column type cannot express: a JSON
 * string that is an array in the domain, one domain concept spread across two
 * columns, a default that only makes sense to this table. That is the whole
 * job, and it is the half worth writing.
 *
 * Getting this wrong is not a harmless duplicate. A mapper that called
 * `Uuid7::fromBytes()` on an id was handed the 36-character string the hydrator
 * had already produced and threw «Expected 16 bytes, got 36» — and because the
 * write engine maps every persisted row back to its domain model, it took down
 * every scheduled job on the first history row, whichever job it was. The
 * matching `toBytes()` on the write side happened to work, which is worse: the
 * pair looked deliberate. Two mappers had it, neither was caught by anything,
 * and both survived a sweep that rewrote nineteen mappers — so the rule is now
 * enforced by `semitexa.mapperTypeConversion` rather than left to be read.
 *
 * Binding a uuid into a hand-written `WHERE` is a different thing and stays
 * correct: a raw query never passes through hydration. It belongs in the
 * repository, not here.
 */
interface ResourceModelMapperInterface
{
    /**
     * @param object $resourceModel already hydrated — column types are converted
     */
    public function toDomain(object $resourceModel): object;

    /**
     * @return object with domain-shaped values; the hydrator converts the column types
     */
    public function toSourceModel(object $domainModel): object;
}

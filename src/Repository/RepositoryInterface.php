<?php

declare(strict_types=1);

namespace Semitexa\Orm\Repository;

/**
 * @deprecated Nothing implements this contract and nothing reads it. The
 *             ORM's repository is DomainRepository, which does not implement
 *             it: its findAll() and findBy() take relations and a nullable
 *             limit that this interface lacks. Type against DomainRepository,
 *             or declare your module's own repository contract and satisfy it
 *             with #[SatisfiesRepositoryContract].
 */
interface RepositoryInterface
{
    /**
     * Find a single entity by its primary key.
     * Returns Domain object or null.
     */
    public function findById(int|string $id): ?object;

    /**
     * Find all entities up to $limit rows.
     * Returns array of Domain objects.
     *
     * @return object[]
     */
    public function findAll(int $limit = 1000): array;

    /**
     * Find entities by criteria (column => value pairs).
     * Returns array of Domain objects.
     *
     * @param array<string, mixed> $criteria
     * @return object[]
     */
    public function findBy(array $criteria): array;

    /**
     * Find a single entity matching criteria.
     * Returns the first match or null.
     *
     * @param array<string, mixed> $criteria
     */
    public function findOneBy(array $criteria): ?object;

    /**
     * Find entities matching the filter criteria set on the resource (e.g. via filterByX()).
     * Accepts only the repository's resource type; the resource must supply getFilterCriteria().
     *
     * @deprecated The criteria come from FilterableResourceInterface, which is
     *             deprecated. Use DomainRepository::findBy() criteria or query()->where().
     *
     * @return object[]
     */
    public function find(object $resource): array;

    /**
     * Find a single entity matching the filter criteria set on the resource.
     *
     * @deprecated The criteria come from FilterableResourceInterface, which is
     *             deprecated. Use DomainRepository::findOneBy() or query()->where().
     *
     * @return object|null
     */
    public function findOne(object $resource): ?object;

    /**
     * Save (insert or update) an entity.
     * Accepts Resource or Domain Entity.
     */
    public function save(object $entity): void;

    /**
     * Delete an entity.
     * Accepts Resource or Domain Entity.
     */
    public function delete(object $entity): void;
}

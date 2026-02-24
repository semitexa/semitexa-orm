<?php

declare(strict_types=1);

namespace Semitexa\Orm\Repository;

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

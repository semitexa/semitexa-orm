<?php

declare(strict_types=1);

namespace Semitexa\Orm\Sync;

use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Attribute\FromTable;

class SeedRunner
{
    public function __construct(
        private readonly DatabaseAdapterInterface $adapter,
    ) {}

    /**
     * Discover all Resource classes with defaults() method,
     * call defaults() on each, and upsert the returned instances.
     *
     * @return array<string, array{inserted: int, updated: int, unchanged: int}>
     *         Keyed by table name
     */
    public function run(): array
    {
        $classes = ClassDiscovery::findClassesWithAttribute(FromTable::class);
        $upsert = new SmartUpsert($this->adapter);

        $results = [];

        foreach ($classes as $className) {
            if (!method_exists($className, 'defaults')) {
                continue;
            }

            $defaults = $className::defaults();

            if ($defaults === []) {
                continue;
            }

            $ref = new \ReflectionClass($className);
            $fromTableAttrs = $ref->getAttributes(FromTable::class);

            if ($fromTableAttrs === []) {
                continue;
            }

            /** @var FromTable $fromTable */
            $fromTable = $fromTableAttrs[0]->newInstance();
            $tableName = $fromTable->name;

            $stats = $upsert->upsert($defaults);

            if (!isset($results[$tableName])) {
                $results[$tableName] = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0];
            }

            $results[$tableName]['inserted'] += $stats['inserted'];
            $results[$tableName]['updated'] += $stats['updated'];
            $results[$tableName]['unchanged'] += $stats['unchanged'];
        }

        return $results;
    }
}

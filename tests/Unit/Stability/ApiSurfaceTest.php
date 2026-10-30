<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Stability;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What semitexa/orm promises (docs: get-started/stability): the symbols
 * marked @api, and only those. The list is pinned so the promise grows only
 * on purpose: a mark added or dropped shows here, in the same review as the
 * code. A dropped one is a break, owed a 90-day deprecation first.
 */
final class ApiSurfaceTest extends TestCase
{
    #[Test]
    public function the_api_surface_is_the_one_promised(): void
    {
        self::assertSame([
            'Semitexa\Orm\Adapter\DatabaseType',
            'Semitexa\Orm\Adapter\MySqlType',
            'Semitexa\Orm\Adapter\SqliteType',
            'Semitexa\Orm\Attribute\AsMapper',
            'Semitexa\Orm\Attribute\AsRepository',
            'Semitexa\Orm\Attribute\BelongsTo',
            'Semitexa\Orm\Attribute\Column',
            'Semitexa\Orm\Attribute\Connection',
            'Semitexa\Orm\Attribute\Filterable',
            'Semitexa\Orm\Attribute\FromTable',
            'Semitexa\Orm\Attribute\HasMany',
            'Semitexa\Orm\Attribute\Index',
            'Semitexa\Orm\Attribute\ManyToMany',
            'Semitexa\Orm\Attribute\OneToOne',
            'Semitexa\Orm\Attribute\PrimaryKey',
            'Semitexa\Orm\Attribute\ResourceKey',
            'Semitexa\Orm\Attribute\SelfManagedTable',
            'Semitexa\Orm\Attribute\SoftDelete',
            'Semitexa\Orm\Attribute\TenantExempt',
            'Semitexa\Orm\Attribute\TenantScoped',
            'Semitexa\Orm\Attribute\Version',
            'Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface',
            'Semitexa\Orm\Domain\Enum\ForeignKeyAction',
            'Semitexa\Orm\Domain\Enum\RelationWritePolicy',
            'Semitexa\Orm\OrmManager::repository()',
            'Semitexa\Orm\Repository\DomainRepository::count()',
            'Semitexa\Orm\Repository\DomainRepository::delete()',
            'Semitexa\Orm\Repository\DomainRepository::exists()',
            'Semitexa\Orm\Repository\DomainRepository::findAll()',
            'Semitexa\Orm\Repository\DomainRepository::findBy()',
            'Semitexa\Orm\Repository\DomainRepository::findById()',
            'Semitexa\Orm\Repository\DomainRepository::findByIdOrFail()',
            'Semitexa\Orm\Repository\DomainRepository::findOneBy()',
            'Semitexa\Orm\Repository\DomainRepository::forTenant()',
            'Semitexa\Orm\Repository\DomainRepository::insert()',
            'Semitexa\Orm\Repository\DomainRepository::paginate()',
            'Semitexa\Orm\Repository\DomainRepository::query()',
            'Semitexa\Orm\Repository\DomainRepository::update()',
            'Semitexa\Orm\Repository\DomainRepository::withoutTenantScope()',
            'Semitexa\Orm\Repository\PaginatedResult',
            'Semitexa\Orm\Trait\HasTimestamps',
            'Semitexa\Orm\Trait\HasTimestamps::$created_at',
            'Semitexa\Orm\Trait\HasTimestamps::$updated_at',
            'Semitexa\Orm\Trait\HasUuidV7',
            'Semitexa\Orm\Trait\HasUuidV7::$id',
        ], self::marked());
    }

    /**
     * Every class-like whose own docblock carries @api, and every member marked
     * on its own (`Class::method()`, `Class::CONST`, `Class::$property`). Only a
     * file that mentions @api is loaded, so the scan needs nothing the package
     * merely suggests.
     *
     * @return list<string>
     */
    private static function marked(): array
    {
        $src = dirname(__DIR__, 3) . '/src';
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source, 'cannot read ' . $file->getPathname());
            if (!str_contains($source, '@api')) {
                continue;
            }
            $class = 'Semitexa\\Orm\\' . str_replace('/', '\\', substr($file->getPathname(), strlen($src) + 1, -4));
            if (!class_exists($class) && !interface_exists($class) && !trait_exists($class)) {
                throw new \RuntimeException($file->getPathname() . ' mentions @api but declares no ' . $class);
            }
            $reflection = new \ReflectionClass($class);
            if (self::isApi($reflection->getDocComment())) {
                $found[] = $class;
            }
            $members = [
                ...array_map(static fn (\ReflectionMethod $m): array => [$m, $m->getName() . '()'], $reflection->getMethods()),
                ...array_map(static fn (\ReflectionClassConstant $c): array => [$c, $c->getName()], $reflection->getReflectionConstants()),
                ...array_map(static fn (\ReflectionProperty $p): array => [$p, '$' . $p->getName()], $reflection->getProperties()),
            ];
            foreach ($members as [$member, $name]) {
                // A trait's members are copied into its users; here only the declaring class-like counts.
                if ($member->getDeclaringClass()->getName() === $class && self::isApi($member->getDocComment())) {
                    $found[] = $class . '::' . $name;
                }
            }
        }
        sort($found);

        return $found;
    }

    private static function isApi(string|false $doc): bool
    {
        return $doc !== false && preg_match('/^[ \t]*(?:\*[ \t]?|\/\*\*[ \t]*)@api\b/m', $doc) === 1;
    }
}

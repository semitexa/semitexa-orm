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
        ], self::marked(dirname(__DIR__, 3) . '/src', 'Semitexa\\Orm\\'));
    }

    /**
     * A trait's members are copied into every class that uses it, and reflection
     * names that class as their declaring one: a member marked in the trait is
     * the trait's promise, not one more for each user.
     */
    #[Test]
    public function a_trait_member_is_counted_once_on_the_trait(): void
    {
        $dir = sys_get_temp_dir() . '/orm-api-surface-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $ns = 'Probe' . bin2hex(random_bytes(4));
        try {
            file_put_contents($dir . '/Stamps.php', "<?php\nnamespace {$ns};\n\n/** @api */\ntrait Stamps\n{\n    /** @api */\n    public string \$id = '';\n\n    /** @api */\n    public function touch(): void {}\n}\n");
            file_put_contents($dir . '/Model.php', "<?php\nnamespace {$ns};\n\n/** @api */\nfinal class Model\n{\n    use Stamps;\n}\n");

            self::assertSame(["{$ns}\\Model", "{$ns}\\Stamps", "{$ns}\\Stamps::\$id", "{$ns}\\Stamps::touch()"], self::marked($dir, $ns . '\\'));
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    /**
     * Every class-like whose own docblock carries @api, and every member marked
     * on its own (`Class::method()`, `Class::CONST`, `Class::$property`). Only a
     * file that mentions @api is loaded, so the scan needs nothing the package
     * merely suggests.
     *
     * @return list<string>
     */
    private static function marked(string $src, string $prefix): array
    {
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
            $class = $prefix . str_replace('/', '\\', substr($file->getPathname(), strlen($src) + 1, -4));
            // A probe outside the autoloader is loaded by hand.
            if (!class_exists($class) && !interface_exists($class) && !trait_exists($class)) {
                require_once $file->getPathname();
            }
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
                // Inherited members count on their parent; a trait's copies, which reflection
                // reports as declared here, count on the trait.
                if ($member->getDeclaringClass()->getName() === $class && !self::fromTrait($reflection, $name) && self::isApi($member->getDocComment())) {
                    $found[] = $class . '::' . $name;
                }
            }
        }
        sort($found);

        return $found;
    }

    /**
     * Whether a trait used here (or by one of its traits) declares the member `name()`, `NAME` or `$name`.
     *
     * @param \ReflectionClass<object> $class
     */
    private static function fromTrait(\ReflectionClass $class, string $name): bool
    {
        foreach ($class->getTraits() as $trait) {
            $declared = match (true) {
                str_ends_with($name, '()') => $trait->hasMethod(substr($name, 0, -2)),
                str_starts_with($name, '$') => $trait->hasProperty(substr($name, 1)),
                default => $trait->hasConstant($name),
            };
            if ($declared || self::fromTrait($trait, $name)) {
                return true;
            }
        }

        return false;
    }

    private static function isApi(string|false $doc): bool
    {
        return $doc !== false && preg_match('/^[ \t]*(?:\*[ \t]?|\/\*\*[ \t]*)@api\b/m', $doc) === 1;
    }
}

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
            'Semitexa\Orm\Metadata\ColumnRef',
            'Semitexa\Orm\Metadata\HasColumnReferences',
            'Semitexa\Orm\Metadata\HasRelationReferences',
            'Semitexa\Orm\Metadata\RelationRef',
            'Semitexa\Orm\OrmManager::repository()',
            'Semitexa\Orm\Query\Direction',
            'Semitexa\Orm\Query\Operator',
            'Semitexa\Orm\Query\ResourceModelQuery::avg()',
            'Semitexa\Orm\Query\ResourceModelQuery::count()',
            'Semitexa\Orm\Query\ResourceModelQuery::countBy()',
            'Semitexa\Orm\Query\ResourceModelQuery::countByDay()',
            'Semitexa\Orm\Query\ResourceModelQuery::exists()',
            'Semitexa\Orm\Query\ResourceModelQuery::fetchAll()',
            'Semitexa\Orm\Query\ResourceModelQuery::fetchAllAs()',
            'Semitexa\Orm\Query\ResourceModelQuery::fetchOne()',
            'Semitexa\Orm\Query\ResourceModelQuery::fetchOneAs()',
            'Semitexa\Orm\Query\ResourceModelQuery::forTenant()',
            'Semitexa\Orm\Query\ResourceModelQuery::includeSoftDeleted()',
            'Semitexa\Orm\Query\ResourceModelQuery::limit()',
            'Semitexa\Orm\Query\ResourceModelQuery::max()',
            'Semitexa\Orm\Query\ResourceModelQuery::min()',
            'Semitexa\Orm\Query\ResourceModelQuery::offset()',
            'Semitexa\Orm\Query\ResourceModelQuery::onlySoftDeleted()',
            'Semitexa\Orm\Query\ResourceModelQuery::orWhere()',
            'Semitexa\Orm\Query\ResourceModelQuery::orWhereNotNull()',
            'Semitexa\Orm\Query\ResourceModelQuery::orWhereNull()',
            'Semitexa\Orm\Query\ResourceModelQuery::orderBy()',
            'Semitexa\Orm\Query\ResourceModelQuery::paginate()',
            'Semitexa\Orm\Query\ResourceModelQuery::paginateAs()',
            'Semitexa\Orm\Query\ResourceModelQuery::sum()',
            'Semitexa\Orm\Query\ResourceModelQuery::where()',
            'Semitexa\Orm\Query\ResourceModelQuery::whereAnyLike()',
            'Semitexa\Orm\Query\ResourceModelQuery::whereBetween()',
            'Semitexa\Orm\Query\ResourceModelQuery::whereIn()',
            'Semitexa\Orm\Query\ResourceModelQuery::whereLike()',
            'Semitexa\Orm\Query\ResourceModelQuery::whereNotIn()',
            'Semitexa\Orm\Query\ResourceModelQuery::whereNotLike()',
            'Semitexa\Orm\Query\ResourceModelQuery::whereNotNull()',
            'Semitexa\Orm\Query\ResourceModelQuery::whereNull()',
            'Semitexa\Orm\Query\ResourceModelQuery::withRelation()',
            'Semitexa\Orm\Query\ResourceModelQuery::withoutTenantScope()',
            'Semitexa\Orm\Query\SystemScopeToken::issue()',
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
     * the trait's promise, not one more for each user, under its own name or an
     * alias. A method the class declares itself over the trait's is the class's.
     * A mark may sit up to four spaces in; further in, it is an example in the prose.
     */
    #[Test]
    public function a_trait_member_is_counted_once_on_the_trait(): void
    {
        $dir = sys_get_temp_dir() . '/orm-api-surface-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $ns = 'Probe' . bin2hex(random_bytes(4));
        // The probe namespace is outside composer's map: load its files from $dir, in whatever order the scan meets them.
        $load = static function (string $class) use ($dir, $ns): void {
            if (str_starts_with($class, $ns . '\\') && is_file($path = $dir . '/' . substr($class, strlen($ns) + 1) . '.php')) {
                require $path;
            }
        };
        spl_autoload_register($load);
        try {
            file_put_contents($dir . '/Stamps.php', "<?php\nnamespace {$ns};\n\n/** @api */\ntrait Stamps\n{\n    /** @api */\n    public string \$id = '';\n\n    /** @api */\n    public function touch(): void {}\n}\n");
            file_put_contents($dir . '/Model.php', "<?php\nnamespace {$ns};\n\n/** @api */\nfinal class Model\n{\n    use Stamps { touch as refresh; }\n}\n");
            file_put_contents($dir . '/Override.php', "<?php\nnamespace {$ns};\n\n/**\n *  @api\n */\nfinal class Override\n{\n    use Stamps;\n\n    /** @api */\n    public function touch(): void {}\n}\n");

            file_put_contents($dir . '/Prose.php', "<?php\nnamespace {$ns};\n\n/**\n * Mark it so:\n *\n *     @api\n */\nfinal class Prose\n{\n}\n");

            self::assertSame([
                "{$ns}\\Model",
                "{$ns}\\Override",
                "{$ns}\\Override::touch()",
                "{$ns}\\Stamps",
                "{$ns}\\Stamps::\$id",
                "{$ns}\\Stamps::touch()",
            ], self::marked($dir, $ns . '\\'));
        } finally {
            spl_autoload_unregister($load);
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
                // reports as declared here, count on the trait. A copied method keeps the
                // trait's file (aliased or not); a property or constant only its name.
                $copied = $member instanceof \ReflectionMethod
                    ? $member->getFileName() !== $reflection->getFileName()
                    : self::fromTrait($reflection, $name);
                if ($member->getDeclaringClass()->getName() === $class && !$copied && self::isApi($member->getDocComment())) {
                    $found[] = $class . '::' . $name;
                }
            }
        }
        sort($found);

        return $found;
    }

    /**
     * Whether a trait used here (or by one of its traits) declares the property `$name` or constant `NAME`.
     *
     * @param \ReflectionClass<object> $class
     */
    private static function fromTrait(\ReflectionClass $class, string $name): bool
    {
        foreach ($class->getTraits() as $trait) {
            $declared = str_starts_with($name, '$') ? $trait->hasProperty(substr($name, 1)) : $trait->hasConstant($name);
            if ($declared || self::fromTrait($trait, $name)) {
                return true;
            }
        }

        return false;
    }

    private static function isApi(string|false $doc): bool
    {
        return $doc !== false && preg_match('/^[ \t]*(?:\*[ \t]{0,4}|\/\*\*[ \t]{0,4})@api\b/m', $doc) === 1;
    }
}

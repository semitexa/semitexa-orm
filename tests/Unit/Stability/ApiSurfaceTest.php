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
            // orm-gap-5-expression-order-group (design: Fable 17.11): grouped and distinct reads on a sub-object.
            'Semitexa\Orm\Query\ResourceModelAggregate::distinctValues()',
            'Semitexa\Orm\Query\ResourceModelAggregate::maxBy()',
            'Semitexa\Orm\Query\ResourceModelAggregate::sumBy()',
            'Semitexa\Orm\Query\ResourceModelQuery::aggregate()',
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
            'Semitexa\Orm\Query\ResourceModelQuery::forUpdate()',
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
            'Semitexa\Orm\Query\ResourceModelQuery::orderByValues()',
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
            'Semitexa\Orm\Repository\DomainRepository::deleteWhere()',
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
            'Semitexa\Orm\Repository\DomainRepository::upsert()',
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
     * alias. A method the class declares itself over the trait's is the class's,
     * and so is a property it redeclares with a docblock of its own.
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
            file_put_contents($dir . '/Override.php', "<?php\nnamespace {$ns};\n\n/**\n *  @api\n */\nfinal class Override\n{\n    use Stamps;\n\n    /** @api Override's own, redeclared. */\n    public string \$id = '';\n\n    /** @api */\n    public function touch(): void {}\n}\n");

            // A trait that redeclares Stamps' $id with its own mark owns it; a class
            // using that trait only copies it (review, orm#117).
            file_put_contents($dir . '/Outer.php', "<?php\nnamespace {$ns};\n\ntrait Outer\n{\n    use Stamps;\n\n    /** @api Outer's own. */\n    public string \$id = '';\n}\n");
            file_put_contents($dir . '/Nested.php', "<?php\nnamespace {$ns};\n\n/** @api */\nfinal class Nested\n{\n    use Outer;\n}\n");
            // Two traits with the same compatible static property: PHP gives the class
            // the LAST trait's docblock; it is still a copy (review, semitexa-api#43).
            file_put_contents($dir . '/Left.php', "<?php\nnamespace {$ns};\n\ntrait Left\n{\n    /** @api Left */\n    public static string \$tag = '';\n}\n");
            file_put_contents($dir . '/Right.php', "<?php\nnamespace {$ns};\n\ntrait Right\n{\n    /** @api Right */\n    public static string \$tag = '';\n}\n");
            file_put_contents($dir . '/Both.php', "<?php\nnamespace {$ns};\n\n/** @api */\nfinal class Both\n{\n    use Left, Right;\n}\n");
            file_put_contents($dir . '/Prose.php', "<?php\nnamespace {$ns};\n\n/**\n * Mark it so:\n *\n *     @api\n */\nfinal class Prose\n{\n}\n");

            self::assertSame([
                "{$ns}\\Both",
                "{$ns}\\Left::\$tag",
                "{$ns}\\Model",
                "{$ns}\\Nested",
                "{$ns}\\Outer::\$id",
                "{$ns}\\Override",
                "{$ns}\\Override::\$id",
                "{$ns}\\Override::touch()",
                "{$ns}\\Right::\$tag",
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
                // A class that redeclares the property or constant (PHP allows a
                // compatible redeclaration) wrote its own docblock: a different
                // one is the class's own declaration, and its mark its own promise.
                $copied = $member instanceof \ReflectionMethod
                    ? $member->getFileName() !== $reflection->getFileName()
                    : in_array($member->getDocComment(), self::fromTrait($reflection, $name), true);
                if ($member->getDeclaringClass()->getName() === $class && !$copied && self::isApi($member->getDocComment())) {
                    $found[] = $class . '::' . $name;
                }
            }
        }
        sort($found);

        return $found;
    }

    /**
     * The docblocks of the property `$name` or constant `NAME` as each trait used
     * here declares it (a trait that does not, through the traits it uses); empty
     * when no trait does. Every one, not the first: PHP keeps the FIRST trait's
     * docblock for a compatible instance property and the LAST one's for a static
     * property (checked on PHP 8.4), so a copy is whichever of them it matches
     * (review, semitexa-api#43 / authorization#35 / ssr#181).
     *
     * @param \ReflectionClass<object> $class
     * @return list<string|false>
     */
    private static function fromTrait(\ReflectionClass $class, string $name): array
    {
        $docs = [];
        // The trait used here first: its reflection already holds what it
        // copied from its own traits, or its own redeclaration over them, which
        // is what this class received (review, orm#117).
        foreach ($class->getTraits() as $trait) {
            if (str_starts_with($name, '$') ? $trait->hasProperty(substr($name, 1)) : $trait->hasConstant($name)) {
                $docs[] = str_starts_with($name, '$') ? $trait->getProperty(substr($name, 1))->getDocComment() : ($trait->getReflectionConstant($name) ?: null)?->getDocComment() ?? false;
                continue;
            }
            array_push($docs, ...self::fromTrait($trait, $name));
        }

        return $docs;
    }

    private static function isApi(string|false $doc): bool
    {
        return $doc !== false && preg_match('/^[ \t]*(?:\*[ \t]{0,4}|\/\*\*[ \t]{0,4})@api\b/m', $doc) === 1;
    }
}

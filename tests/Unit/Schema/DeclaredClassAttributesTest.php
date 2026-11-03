<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Application\Service\Schema\DeclaredClassAttributes;

/** Only the attributes on the class's own declaration count, read from source. */
final class DeclaredClassAttributesTest extends TestCase
{
    #[Test]
    public function a_declared_table_and_its_connection_are_read(): void
    {
        $source = "<?php\nnamespace App;\nuse Semitexa\\Orm\\Attribute\\FromTable;\n/** An order. */\n#[FromTable(name: 'orders')]\n#[\\Semitexa\\Orm\\Attribute\\Connection(name: 'analytics')]\nfinal class Orders extends Missing {}\n";
        $declared = DeclaredClassAttributes::of($source, 'App\\Orders');

        self::assertNotNull($declared);
        self::assertTrue($declared->fromTable);
        self::assertSame('analytics', $declared->connection);
    }

    #[Test]
    public function a_mention_outside_the_declaration_is_no_table(): void
    {
        $source = "<?php\nnamespace App;\n/**\n * Example: #[FromTable(name: 'x')]\n */\n// #[FromTable(name: 'y')]\n#[Deprecated]\nfinal class Helper { const T = \"#[FromTable(name: 'z')]\"; }\n";
        $declared = DeclaredClassAttributes::of($source, 'App\\Helper');

        self::assertNotNull($declared);
        self::assertFalse($declared->fromTable);
        self::assertSame('default', $declared->connection);
    }

    /** Raised on orm#105: the namespace, the imports and string contents are read as the engine reads them. */
    #[Test]
    public function names_resolve_through_namespace_and_imports_and_strings_are_text(): void
    {
        $twoNamespaces = "<?php\nnamespace Foo;\nuse Semitexa\\Orm\\Attribute\\FromTable;\n#[FromTable(name: 'o')]\nfinal class Orders {}\nnamespace Bar;\nfinal class Orders {}\n";
        self::assertTrue(DeclaredClassAttributes::of($twoNamespaces, 'Foo\\Orders')?->fromTable);
        self::assertFalse(DeclaredClassAttributes::of($twoNamespaces, 'Bar\\Orders')?->fromTable, 'the same short name in another namespace is another class');

        $alias = "<?php\nnamespace App;\nuse Semitexa\\Orm\\Attribute\\{FromTable as Table, Connection as Db};\n#[Table(name: 'e')]\n#[Db('analytics')]\nfinal class Events {}\n";
        $declared = DeclaredClassAttributes::of($alias, 'App\\Events');
        self::assertSame([true, 'analytics'], [$declared?->fromTable, $declared?->connection]);

        $strings = "<?php\nnamespace App;\nuse Semitexa\\Orm\\Attribute\\FromTable;\n#[Tag(label: '[', note: ' FromTable(name: orders)')]\nfinal class Helper {}\n";
        $declared = DeclaredClassAttributes::of($strings, 'App\\Helper');
        self::assertNotNull($declared, 'a [ inside a string does not swallow the declaration');
        self::assertFalse($declared->fromTable, 'FromTable inside a string is text');

        $unimported = "<?php\nnamespace App;\n#[FromTable(name: 'x')]\nfinal class Local {}\n";
        self::assertFalse(DeclaredClassAttributes::of($unimported, 'App\\Local')?->fromTable, 'an unimported FromTable is App\\FromTable, not the ORM\'s');
    }

    /** Raised on orm#105 (third review): trait uses, case, groups, escapes, `namespace\\`, and non-literal connections. */
    #[Test]
    public function the_file_is_read_as_php_reads_it(): void
    {
        $trait = "<?php\nnamespace App;\nuse Semitexa\\Orm\\Attribute\\FromTable;\nfinal class First { use \\Other\\FromTable; }\n#[FromTable(name: 'b')]\nfinal class Second {}\n";
        self::assertTrue(DeclaredClassAttributes::of($trait, 'App\\Second')?->fromTable, 'a trait use inside a class body is no import');

        $upper = "<?php\nnamespace App;\n#[\\SEMITEXA\\ORM\\ATTRIBUTE\\FROMTABLE(name: 'u')]\nfinal class Upper {}\n";
        self::assertTrue(DeclaredClassAttributes::of($upper, 'App\\Upper')?->fromTable, 'class names are case-insensitive');

        $group = "<?php\nnamespace App;\nuse Semitexa\\Orm\\Attribute\\{function helper, FromTable};\n#[FromTable(name: 'g')]\nfinal class Grouped {}\n";
        self::assertTrue(DeclaredClassAttributes::of($group, 'App\\Grouped')?->fromTable, 'only the function member of a group is skipped');

        $escaped = "<?php\nnamespace App;\nuse Semitexa\\Orm\\Attribute\\{FromTable, Connection};\n#[FromTable(name: 'e')]\n#[Connection('analytics\\n')]\nfinal class Escaped {}\n";
        self::assertSame('analytics\\n', DeclaredClassAttributes::of($escaped, 'App\\Escaped')?->connection, 'a single-quoted \\n stays two characters');

        $relative = "<?php\nnamespace Semitexa\\Orm\\Attribute;\n#[namespace\\FromTable(name: 'r')]\nfinal class Relative {}\n";
        self::assertTrue(DeclaredClassAttributes::of($relative, 'Semitexa\\Orm\\Attribute\\Relative')?->fromTable);

        foreach (["DB_ALIAS", "name: DB_ALIAS", "'anal' . 'ytics'", "other: 'analytics'", "Name: 'analytics'"] as $argument) {
            $source = "<?php\nnamespace App;\nuse Semitexa\\Orm\\Attribute\\{FromTable, Connection};\n#[FromTable(name: 'c')]\n#[Connection({$argument})]\nfinal class Computed {}\n";
            $declared = DeclaredClassAttributes::of($source, 'App\\Computed');
            self::assertNotNull($declared, "{$argument}: the declaration is found");
            self::assertNull($declared->connection, "{$argument} is no literal: the connection is unknown");
        }
        foreach (['', '()'] as $bare) {
            $source = "<?php\nnamespace App;\nuse Semitexa\\Orm\\Attribute\\Connection;\n#[Connection{$bare}]\nfinal class Bare {}\n";
            self::assertSame('default', DeclaredClassAttributes::of($source, 'App\\Bare')?->connection, "#[Connection{$bare}] is the default connection");
        }
        $anonymous = "<?php\nnamespace App;\nuse Semitexa\\Orm\\Attribute\\FromTable;\n\$x = new class { use \\Other\\FromTable; };\n#[FromTable(name: 'a')]\nfinal class AfterAnonymous {}\n";
        self::assertTrue(DeclaredClassAttributes::of($anonymous, 'App\\AfterAnonymous')?->fromTable, 'an anonymous class body holds no import');
        $named = "<?php\nnamespace App;\nuse Semitexa\\Orm\\Attribute\\Connection;\n#[Connection(name: 'analytics')]\nfinal class Named {}\n";
        self::assertSame('analytics', DeclaredClassAttributes::of($named, 'App\\Named')?->connection);
    }

    #[Test]
    public function the_attributes_of_another_class_in_the_file_are_not_this_one_s(): void
    {
        $source = "<?php\nuse Semitexa\\Orm\\Attribute\\FromTable;\n#[FromTable(name: 'a')]\nfinal class First {}\nfinal class Second {}\n";

        self::assertFalse(DeclaredClassAttributes::of($source, 'Second')?->fromTable);
        self::assertTrue(DeclaredClassAttributes::of($source, 'First')?->fromTable);
        self::assertNull(DeclaredClassAttributes::of($source, 'Third'), 'a class the file does not declare');
    }
}

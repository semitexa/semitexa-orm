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

    #[Test]
    public function the_attributes_of_another_class_in_the_file_are_not_this_one_s(): void
    {
        $source = "<?php\n#[FromTable(name: 'a')]\nfinal class First {}\nfinal class Second {}\n";

        self::assertFalse(DeclaredClassAttributes::of($source, 'Second')?->fromTable);
        self::assertTrue(DeclaredClassAttributes::of($source, 'First')?->fromTable);
        self::assertNull(DeclaredClassAttributes::of($source, 'Third'), 'a class the file does not declare');
    }
}

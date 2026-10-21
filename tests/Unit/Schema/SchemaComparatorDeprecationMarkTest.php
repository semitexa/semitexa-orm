<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Application\Service\Schema\SchemaComparator;
use Semitexa\Orm\Domain\Model\ColumnDefinition;
use Semitexa\Orm\Domain\Model\DbColumnState;
use Semitexa\Orm\Adapter\MySqlType;

/**
 * A column marked SEMITEXA_DEPRECATED (phase 1) that is back in code kept the
 * mark, since only type, nullability, auto-increment and default were
 * compared: the next sync that missed the column dropped it with no warning
 * phase.
 */
final class SchemaComparatorDeprecationMarkTest extends TestCase
{
    #[Test]
    public function a_column_back_in_code_has_its_mark_cleared(): void
    {
        $changes = $this->compare(new ColumnDefinition('title', MySqlType::Varchar, 'string', length: 255), 'SEMITEXA_DEPRECATED');

        self::assertSame(['comment: deprecation mark cleared (back in code)'], $changes);
    }

    #[Test]
    public function a_column_the_code_itself_deprecates_keeps_its_mark(): void
    {
        $changes = $this->compare(new ColumnDefinition('title', MySqlType::Varchar, 'string', length: 255, isDeprecated: true), 'SEMITEXA_DEPRECATED');

        self::assertSame([], $changes);
    }

    /** @return list<string> */
    private function compare(ColumnDefinition $code, string $comment): array
    {
        $db = new DbColumnState('title', 'varchar', 'varchar(255)', false, null, false, false, 255, null, null, $comment);
        $comparator = (new \ReflectionClass(SchemaComparator::class))->newInstanceWithoutConstructor();

        return (new \ReflectionMethod($comparator, 'compareColumn'))->invoke($comparator, $code, $db);
    }
}

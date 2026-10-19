<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Tests\Unit\Utility;

use NITSAN\NsT3AL\Utility\CsvCellEscaper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class CsvCellEscaperTest extends UnitTestCase
{
    public static function cells(): \Generator
    {
        yield 'equals' => ['=HYPERLINK("http://x","y")', '\'=HYPERLINK("http://x","y")'];
        yield 'plus' => ['+1 more', "'+1 more"];
        yield 'minus' => ['-5 items', "'-5 items"];
        yield 'at' => ['@SUM(A1)', "'@SUM(A1)"];
        yield 'tab' => ["\t=1", "'\t=1"];
        yield 'carriage return' => ["\r=1", "'\r=1"];
        yield 'apostrophe before formula' => ["'=1", "''=1"];
        yield 'plain text' => ['Save', 'Save'];
        yield 'formula char later' => ['a = b', 'a = b'];
        yield 'apostrophe text' => ["'quoted'", "'quoted'"];
        yield 'empty' => ['', ''];
    }

    #[Test]
    #[DataProvider('cells')]
    public function escapeNeutralisesFormulasAndUnescapeRestoresTheValue(string $value, string $escaped): void
    {
        self::assertSame($escaped, CsvCellEscaper::escape($value));
        self::assertSame($value, CsvCellEscaper::unescape($escaped));
    }

    #[Test]
    public function unescapeKeepsApostropheThatIsNotAnEscape(): void
    {
        self::assertSame("'", CsvCellEscaper::unescape("'"));
        self::assertSame("'hello", CsvCellEscaper::unescape("'hello"));
    }
}

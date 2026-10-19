<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Tests\Unit\Utility;

use NITSAN\NsT3AL\Utility\GridLabels;
use NITSAN\NsT3AL\Utility\XliffDocumentUpdater;
use NITSAN\NsT3AL\Utility\XliffUtility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class GridLabelsTest extends UnitTestCase
{
    private const FILE_LABELS = [
        'title' => ['default' => 'Title', 'de' => 'Titel'],
        'help' => ['default' => "Line one\nLine two", 'de' => "Zeile eins\nZeile zwei"],
        'empty' => ['default' => 'Empty', 'de' => ''],
    ];

    #[Test]
    public function normalizeLineBreaksConvertsCrlfAndCrToLf(): void
    {
        self::assertSame(
            ['a' => ['default' => "one\ntwo\nthree", 'de' => 'eins']],
            GridLabels::normalizeLineBreaks(['a' => ['default' => "one\r\ntwo\rthree", 'de' => 'eins']])
        );
    }

    #[Test]
    public function normalizeLineBreaksLeavesNonTextValuesAlone(): void
    {
        $labels = ['a' => 'not a row', 'b' => ['default' => ['nested']]];
        self::assertSame($labels, GridLabels::normalizeLineBreaks($labels));
    }

    public static function unchangedGrids(): \Generator
    {
        yield 'identical' => [self::FILE_LABELS];
        yield 'browser line breaks' => [[
            'title' => ['default' => 'Title', 'de' => 'Titel'],
            'help' => ['default' => "Line one\r\nLine two", 'de' => "Zeile eins\r\nZeile zwei"],
            'empty' => ['default' => 'Empty', 'de' => ''],
        ]];
        yield 'surrounding whitespace' => [[
            'title' => ['default' => ' Title ', 'de' => "Titel\n"],
            'help' => self::FILE_LABELS['help'],
            'empty' => ['default' => 'Empty', 'de' => '  '],
        ]];
        yield 'missing empty column' => [[
            'title' => self::FILE_LABELS['title'],
            'help' => self::FILE_LABELS['help'],
            'empty' => ['default' => 'Empty'],
        ]];
    }

    #[Test]
    #[DataProvider('unchangedGrids')]
    public function gridWithTheFileTextsDoesNotDiffer(array $grid): void
    {
        self::assertFalse(GridLabels::differ(self::FILE_LABELS, $grid));
    }

    public static function changedGrids(): \Generator
    {
        yield 'filled translation' => [['empty' => ['default' => 'Empty', 'de' => 'Leer']] + self::FILE_LABELS];
        yield 'edited source' => [['title' => ['default' => 'Heading', 'de' => 'Titel']] + self::FILE_LABELS];
        yield 'cleared translation' => [['title' => ['default' => 'Title', 'de' => '']] + self::FILE_LABELS];
        yield 'added key' => [self::FILE_LABELS + ['new' => ['default' => 'New', 'de' => '']]];
        yield 'removed key' => [['title' => self::FILE_LABELS['title'], 'help' => self::FILE_LABELS['help']]];
        yield 'renamed key' => [['heading' => self::FILE_LABELS['title'], 'help' => self::FILE_LABELS['help'], 'empty' => self::FILE_LABELS['empty']]];
        yield 'moved row' => [['help' => self::FILE_LABELS['help'], 'title' => self::FILE_LABELS['title'], 'empty' => self::FILE_LABELS['empty']]];
        yield 'malformed row' => [['title' => 'Titel'] + self::FILE_LABELS];
    }

    #[Test]
    #[DataProvider('changedGrids')]
    public function gridWithOtherKeysOrTextsDiffers(array $grid): void
    {
        self::assertTrue(GridLabels::differ(self::FILE_LABELS, $grid));
    }

    #[Test]
    public function numericKeysCompareLikeTheirStringForm(): void
    {
        self::assertFalse(GridLabels::differ([1 => ['default' => 'One']], ['1' => ['default' => 'One']]));
    }

    #[Test]
    public function browserPostedMultiLineLabelIsNotWrittenAgain(): void
    {
        $xml = "<?xml version=\"1.0\"?>\n<xliff version=\"1.0\">\n\t<file source-language=\"en\" target-language=\"de\">\n\t\t<body>\n"
            . "\t\t\t<trans-unit id=\"help\">\n\t\t\t\t<source>Line one\nLine two</source>\n\t\t\t\t<target>Zeile eins\nZeile zwei</target>\n\t\t\t</trans-unit>\n"
            . "\t\t</body>\n\t</file>\n</xliff>\n";
        $posted = GridLabels::normalizeLineBreaks(['help' => ['default' => "Line one\r\nLine two", 'de' => "Zeile eins\r\nZeile zwei"]]);

        $result = (new XliffDocumentUpdater())->update($xml, ['help' => ['source' => $posted['help']['default'], 'target' => $posted['help']['de']]], [], true, false);

        self::assertFalse($result['changed']);
        self::assertSame($xml, $result['content']);
    }

    #[Test]
    public function translationZeroWithAttributesIsReadAsZero(): void
    {
        $xml = "<?xml version=\"1.0\"?>\n<xliff version=\"1.0\"><file source-language=\"en\" target-language=\"de\"><body>"
            . '<trans-unit id="count"><source>0</source><target state="translated">0</target></trans-unit>'
            . '</body></file></xliff>';

        self::assertSame('0', (new XliffUtility())->stringToArray($xml)['count']['target']);
    }
}

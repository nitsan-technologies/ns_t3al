<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Tests\Unit\Backend\View;

use NITSAN\NsT3AL\Backend\View\EditorViewDataProvider;
use NITSAN\NsT3AL\Tests\Unit\Support\EditorViewDataProviderFactoryTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class EditorViewDataProviderKeyChangesTest extends UnitTestCase
{
    use EditorViewDataProviderFactoryTrait;

    private const LABELS = [
        'save' => ['default' => 'Save', 'de' => 'Speichern'],
        'close' => ['default' => 'Close', 'de' => 'Schließen'],
        'open' => ['default' => 'Open', 'de' => ''],
    ];

    private EditorViewDataProvider $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = $this->createEditorViewDataProvider();
    }

    #[Test]
    public function labelsWithoutKeyChangesAreReturnedUnchanged(): void
    {
        self::assertSame(self::LABELS, $this->subject->applyKeyChanges(self::LABELS, []));
        self::assertSame(self::LABELS, $this->subject->applyKeyChanges(self::LABELS, ['save' => 'save', 'close' => 'close']));
    }

    #[Test]
    public function renamedKeyKeepsItsRowAndPosition(): void
    {
        $result = $this->subject->applyKeyChanges(self::LABELS, ['close' => '  close_button  ']);

        self::assertSame(['save', 'close_button', 'open'], array_keys($result));
        self::assertSame(self::LABELS['close'], $result['close_button']);
    }

    #[Test]
    public function emptiedKeyRemovesTheRow(): void
    {
        $result = $this->subject->applyKeyChanges(self::LABELS, ['open' => '   ']);

        self::assertSame(['save', 'close'], array_keys($result));
    }

    #[Test]
    public function rowsRenamedToTheSameKeyAreMergedWithTheLaterRowWinning(): void
    {
        $labels = [
            'a' => ['default' => 'A', 'de' => 'A de'],
            'b' => ['default' => 'B', 'fr' => 'B fr'],
        ];

        self::assertSame(
            ['x' => ['default' => 'B', 'de' => 'A de', 'fr' => 'B fr']],
            $this->subject->applyKeyChanges($labels, ['a' => 'x', 'b' => 'x'])
        );
    }

    #[Test]
    public function keyRenamesListOnlyRealRenames(): void
    {
        self::assertSame(
            ['close' => 'close_button'],
            $this->subject->buildKeyRenames(['save' => 'save', 'close' => ' close_button ', 'open' => ''])
        );
    }

    public static function sourceChangeCases(): array
    {
        $labels = self::LABELS;
        $added = $labels + ['new' => ['default' => 'New', 'de' => '']];
        $removed = $labels;
        unset($removed['open']);
        $renamed = ['save_button' => $labels['save']] + $labels;
        unset($renamed['save']);
        $englishEdited = array_replace($labels, ['save' => ['default' => 'Store', 'de' => 'Speichern']]);
        $translationEdited = array_replace($labels, ['save' => ['default' => 'Save', 'de' => 'Sichern']]);
        $whitespaceOnly = array_replace($labels, ['save' => ['default' => ' Save ', 'de' => 'Speichern']]);

        return [
            'same grid' => [$labels, false],
            'key added' => [$added, true],
            'key removed' => [$removed, true],
            'key renamed' => [$renamed, true],
            'english text edited' => [$englishEdited, true],
            'translation edited' => [$translationEdited, false],
            'surrounding whitespace only' => [$whitespaceOnly, false],
        ];
    }

    #[Test]
    #[DataProvider('sourceChangeCases')]
    public function sourceChangesAreDetectedOnKeysAndEnglishTextsOnly(array $requested, bool $expected): void
    {
        self::assertSame($expected, $this->subject->hasSourceChanges(self::LABELS, $requested));
    }

    public static function gridOrderCases(): array
    {
        $file = ['save', 'close', 'open'];
        return [
            'untouched grid' => [$file, ['save', 'close', 'open'], [], false],
            'row moved' => [$file, ['close', 'save', 'open'], [], true],
            'row moved to the end' => [$file, ['close', 'open', 'save'], [], true],
            'new key appended' => [$file, ['save', 'close', 'open', 'new'], [], false],
            'new key inserted between rows' => [$file, ['save', 'new', 'close', 'open'], [], true],
            'key removed' => [$file, ['save', 'open'], [], false],
            'key renamed in place' => [$file, ['save', 'shut', 'open'], ['close' => 'shut'], false],
            'two keys swapped by renaming' => [$file, ['close', 'save', 'open'], ['save' => 'close', 'close' => 'save'], false],
            'translation-only keys after the english ones' => [$file, ['save', 'close', 'open', 'extra'], [], false],
            'numeric keys untouched' => [[1, 2, 10], ['1', '2', '10'], [], false],
            'numeric keys moved' => [[1, 2, 10], ['10', '1', '2'], [], true],
            'new file' => [[], ['b', 'a'], [], false],
        ];
    }

    #[Test]
    #[DataProvider('gridOrderCases')]
    public function gridOrderChangeIsDetectedOnlyForMovedRows(array $fileKeys, array $gridKeys, array $renames, bool $expected): void
    {
        self::assertSame($expected, $this->subject->isGridOrderChanged($fileKeys, $gridKeys, $renames));
    }

    #[Test]
    public function unitTextReadsPlainAndAttributedValues(): void
    {
        self::assertSame('Save', $this->subject->unitText('Save'));
        self::assertSame('Save', $this->subject->unitText(['@xml:space' => 'preserve', '_text' => 'Save']));
        self::assertSame('', $this->subject->unitText(null));
    }

    #[Test]
    public function parseUnitsReturnsAnEmptyListForEmptyOrBrokenFiles(): void
    {
        self::assertSame([], $this->subject->parseUnits(''));
        self::assertSame([], $this->subject->parseUnits('<xliff><file>'));
    }
}

<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Tests\Unit\Utility;

use NITSAN\NsT3AL\Tests\Unit\Support\TemporaryDirectoryTrait;
use NITSAN\NsT3AL\Utility\XliffUtility;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class XliffUtilityComputeDiffTest extends UnitTestCase
{
    use TemporaryDirectoryTrait;

    private string $packagePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->packagePath = $this->createTemporaryDirectory() . '/package/';
    }

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
        parent::tearDown();
    }

    /**
     * @param array<string, array{0: string, 1: string}> $units id => [source, target]
     */
    private static function xliff(array $units): string
    {
        $body = '';
        foreach ($units as $id => [$source, $target]) {
            $body .= '<trans-unit id="' . htmlspecialchars($id) . '"><source>' . htmlspecialchars($source)
                . '</source><target>' . htmlspecialchars($target) . '</target></trans-unit>';
        }
        return '<?xml version="1.0" encoding="UTF-8"?><xliff version="1.2" xmlns="urn:oasis:names:tc:xliff:document:1.2">'
            . '<file source-language="en" target-language="de" datatype="plaintext" original="x"><body>'
            . $body . '</body></file></xliff>';
    }

    /**
     * @param array<string, array{0: string, 1: string}> $existing
     * @param array<string, array{0: string, 1: string}> $updated
     * @return array<string, mixed>
     */
    private function diff(array $existing, array $updated): array
    {
        $this->writeFile($this->packagePath . XliffUtility::LANGUAGE_DIR . '/de.locallang.xlf', self::xliff($existing));
        $json = (new XliffUtility())->computeDiff(self::xliff($updated), 'ext', 'locallang.xlf', 'de', $this->packagePath);
        return $json === '' ? [] : json_decode($json, true, 16, JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function idsWithQuotesAreComparedLiterally(): void
    {
        $units = [
            "it's" => ['It is', 'Es ist'],
            'say "hi"' => ['Say hi', 'Sag hallo'],
            "a']|//*[@id='b" => ['Injected', 'Injiziert'],
        ];

        self::assertSame([], $this->diff($units, $units));
    }

    #[Test]
    public function changedAddedAndRemovedUnitsAreStoredAsPlainText(): void
    {
        $diff = $this->diff(
            ['changed' => ['Old', 'Alt & <b>fett</b>'], "o'removed" => ['Gone', 'Weg'], 'same' => ['S', 'S']],
            ['changed' => ['Old', 'Neu'], "n'added" => ['New', 'Neu "x"'], 'same' => ['S', 'S']]
        );

        self::assertSame(
            [
                'changed' => ['target' => ['del' => 'Alt & <b>fett</b>', 'ins' => 'Neu']],
                "n'added" => ['source' => ['ins' => 'New'], 'target' => ['ins' => 'Neu "x"']],
                "o'removed" => ['source' => ['del' => 'Gone'], 'target' => ['del' => 'Weg']],
            ],
            $diff
        );
    }

    #[Test]
    public function duplicateIdsCompareTheLastUnitLikeTypo3(): void
    {
        $withDuplicate = str_replace(
            '<trans-unit id="dup">',
            '<trans-unit id="dup"><source>Dup</source><target></target></trans-unit><trans-unit id="dup">',
            self::xliff(['dup' => ['Dup', 'Doppelt'], 'other' => ['Other', 'Andere']])
        );
        $this->writeFile($this->packagePath . XliffUtility::LANGUAGE_DIR . '/de.locallang.xlf', $withDuplicate);
        $subject = new XliffUtility();

        self::assertSame('', $subject->computeDiff($withDuplicate, 'ext', 'locallang.xlf', 'de', $this->packagePath));
        self::assertSame(
            ['other' => ['target' => ['del' => 'Andere', 'ins' => 'Neu']]],
            json_decode($subject->computeDiff(str_replace('Andere', 'Neu', $withDuplicate), 'ext', 'locallang.xlf', 'de', $this->packagePath), true)
        );
    }

    private function diffForNewFile(array $updated, string $language = 'de'): array
    {
        // Only the English source exists; no target file for the language yet.
        $this->writeFile($this->packagePath . XliffUtility::LANGUAGE_DIR . '/locallang.xlf', self::xliff(array_map(static fn (array $unit): array => [$unit[0], ''], $updated)));
        $json = (new XliffUtility())->computeDiff(self::xliff($updated), 'ext', 'locallang.xlf', $language, $this->packagePath);
        return $json === '' ? [] : json_decode($json, true, 16, JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function newTargetFileLogsTheSavedLabelsAsAddedNeverAsDeleted(): void
    {
        $diff = $this->diffForNewFile([
            'yes' => ['Yes', 'Ja'],
            'no' => ['No', 'Nein'],
            'untranslated' => ['Untranslated', ''],
        ]);

        self::assertSame(
            [
                'yes' => ['source' => ['ins' => 'Yes'], 'target' => ['ins' => 'Ja']],
                'no' => ['source' => ['ins' => 'No'], 'target' => ['ins' => 'Nein']],
                'untranslated' => ['source' => ['ins' => 'Untranslated']],
            ],
            $diff
        );
        self::assertStringNotContainsString('"del"', json_encode($diff, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function newTargetFileWithOnlySomeUnitsDoesNotLogTheOtherSourceLabelsAsDeleted(): void
    {
        // The saved file of a framework language pack may contain only the translated units.
        $this->writeFile($this->packagePath . XliffUtility::LANGUAGE_DIR . '/locallang.xlf', self::xliff([
            'yes' => ['Yes', ''], 'no' => ['No', ''], 'never' => ['Never', ''],
        ]));
        $json = (new XliffUtility())->computeDiff(self::xliff(['yes' => ['Yes', 'Ja QA']]), 'ext', 'locallang.xlf', 'de', $this->packagePath);

        self::assertSame(
            ['yes' => ['source' => ['ins' => 'Yes'], 'target' => ['ins' => 'Ja QA']]],
            json_decode($json, true, 16, JSON_THROW_ON_ERROR)
        );
    }

    #[Test]
    public function existingFileWithChangesLogsOnlyTheChanges(): void
    {
        self::assertSame(
            ['yes' => ['target' => ['del' => 'Ja', 'ins' => 'Jawohl']]],
            $this->diff(['yes' => ['Yes', 'Ja'], 'no' => ['No', 'Nein']], ['yes' => ['Yes', 'Jawohl'], 'no' => ['No', 'Nein']])
        );
    }

    #[Test]
    public function unchangedExistingFileLogsNothing(): void
    {
        $units = ['yes' => ['Yes', 'Ja'], 'no' => ['No', 'Nein']];

        self::assertSame([], $this->diff($units, $units));
    }
}

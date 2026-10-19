<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Tests\Unit\Backend\View;

use NITSAN\NsT3AL\Backend\View\EditorViewDataProvider;
use NITSAN\NsT3AL\Service\ActiveLanguagesProvider;
use NITSAN\NsT3AL\Service\ExtensionAccessService;
use NITSAN\NsT3AL\Tests\Unit\Support\EditorViewDataProviderFactoryTrait;
use NITSAN\NsT3AL\Utility\XliffDocumentUpdater;
use NITSAN\NsT3AL\Utility\XliffUtility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Localization\Locales;
use TYPO3\CMS\Extensionmanager\Utility\ListUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Duplicate / malformed keys, partial CSV imports and the target language list.
 */
final class EditorViewDataProviderGridGuardsTest extends UnitTestCase
{
    use EditorViewDataProviderFactoryTrait;

    private const LABELS = [
        'greeting' => ['default' => 'Hello', 'de' => 'Hallo'],
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
    public function unchangedGridHasNoKeyProblems(): void
    {
        self::assertSame(
            ['duplicates' => [], 'invalid' => []],
            $this->subject->findKeyProblems(self::LABELS, ['greeting' => 'greeting', 'close' => 'close'], array_keys(self::LABELS))
        );
    }

    #[Test]
    public function newRowWithAnExistingKeyIsADuplicate(): void
    {
        $labels = self::LABELS + ['_newkey1791' => ['default' => 'Hi there', 'de' => 'Hi']];

        $problems = $this->subject->findKeyProblems($labels, ['_newkey1791' => ' greeting '], array_keys(self::LABELS));

        self::assertSame(['greeting'], $problems['duplicates']);
        self::assertSame([], $problems['invalid']);
    }

    #[Test]
    public function renameOntoAnotherExistingKeyIsADuplicate(): void
    {
        $problems = $this->subject->findKeyProblems(self::LABELS, ['close' => 'open'], array_keys(self::LABELS));

        self::assertSame(['open'], $problems['duplicates']);
    }

    #[Test]
    public function swappingTwoKeysIsAllowed(): void
    {
        $problems = $this->subject->findKeyProblems(self::LABELS, ['close' => 'open', 'open' => 'close'], array_keys(self::LABELS));

        self::assertSame(['duplicates' => [], 'invalid' => []], $problems);
    }

    #[Test]
    public function emptyNewRowsAndRemovedKeysAreIgnored(): void
    {
        $labels = self::LABELS + ['_newkey1' => ['default' => '', 'de' => ''], '_newkey2' => ['default' => '', 'de' => '']];

        $problems = $this->subject->findKeyProblems($labels, ['_newkey1' => '', '_newkey2' => ' ', 'open' => ''], array_keys(self::LABELS));

        self::assertSame(['duplicates' => [], 'invalid' => []], $problems);
    }

    public static function invalidKeys(): array
    {
        return [
            'apostrophe' => ["it's"],
            'space' => ['two words'],
            'markup' => ['<b>key'],
            'double quote' => ['say"hi'],
            'umlaut' => ['schließen'],
        ];
    }

    #[Test]
    #[DataProvider('invalidKeys')]
    public function newKeysOutsideThePatternAreInvalid(string $key): void
    {
        $labels = self::LABELS + ['_newkey1' => ['default' => 'x', 'de' => '']];

        $problems = $this->subject->findKeyProblems($labels, ['_newkey1' => $key], array_keys(self::LABELS));

        self::assertSame([$key], $problems['invalid']);
    }

    #[Test]
    public function keysImportedWithoutRenameAreValidatedToo(): void
    {
        $labels = self::LABELS + ["it's" => ['default' => 'x', 'de' => '']];

        $problems = $this->subject->findKeyProblems($labels, ["it's" => "it's"], array_keys(self::LABELS));

        self::assertSame(["it's"], $problems['invalid']);
    }

    #[Test]
    public function existingKeysWithUnusualCharactersAreNotRejected(): void
    {
        $labels = ['legacy key' => ['default' => 'x']];

        $problems = $this->subject->findKeyProblems($labels, ['legacy key' => 'legacy key'], ['legacy key']);

        self::assertSame(['duplicates' => [], 'invalid' => []], $problems);
    }

    #[Test]
    public function typicalKeysMatchThePattern(): void
    {
        foreach (['save', 'module.title', 'tx_news_domain_model_news.title', 'labels-2', 'A.b_C-9'] as $key) {
            self::assertSame(1, preg_match(EditorViewDataProvider::KEY_PATTERN, $key), $key);
        }
    }

    #[Test]
    public function partialCsvImportKeepsKeysAndLanguagesThatAreNotInTheCsv(): void
    {
        $existing = [
            'greeting' => ['default' => 'Hello', 'de' => 'Hallo', 'fr' => 'Bonjour'],
            'close' => ['default' => 'Close', 'de' => 'Schließen', 'fr' => 'Fermer'],
        ];
        $imported = [
            'close' => ['default' => 'Close', 'de' => 'Zumachen'],
            'new_key' => ['default' => 'New', 'de' => 'Neu'],
        ];

        $result = $this->subject->mergeImportedLabels($existing, $imported, ['default', 'de', 'fr']);

        self::assertSame(1, $result['updated']);
        self::assertSame(1, $result['added']);
        self::assertSame(
            [
                'greeting' => ['default' => 'Hello', 'de' => 'Hallo', 'fr' => 'Bonjour'],
                'close' => ['default' => 'Close', 'de' => 'Zumachen', 'fr' => 'Fermer'],
                'new_key' => ['default' => 'New', 'de' => 'Neu', 'fr' => ''],
            ],
            $result['labels']
        );
    }

    #[Test]
    public function onlyKeysWithChangedTextCountAsUpdated(): void
    {
        $existing = [
            'greeting' => ['default' => 'Hello', 'de' => 'Hallo'],
            'close' => ['default' => 'Close', 'de' => 'Schließen'],
            'open' => ['default' => 'Open'],
        ];
        $imported = [
            'greeting' => ['default' => 'Hello', 'de' => 'Hallo'],
            'close' => ['default' => 'Close', 'de' => 'Zumachen'],
            'open' => ['default' => 'Open', 'de' => '', '../evil' => 'x'],
        ];

        $result = $this->subject->mergeImportedLabels($existing, $imported, ['default', 'de']);

        self::assertSame(1, $result['updated']);
        self::assertSame(0, $result['added']);
    }

    #[Test]
    public function importedCellsReplaceExistingOnesEvenWhenEmpty(): void
    {
        $result = $this->subject->mergeImportedLabels(
            ['greeting' => ['default' => 'Hello', 'de' => 'Hallo']],
            ['greeting' => ['de' => '']],
            ['default', 'de']
        );

        self::assertSame(['greeting' => ['default' => 'Hello', 'de' => '']], $result['labels']);
    }

    #[Test]
    public function columnsOutsideTheGridLanguagesAreDropped(): void
    {
        $result = $this->subject->mergeImportedLabels(
            ['greeting' => ['default' => 'Hello']],
            ['greeting' => ['default' => 'Hi', '../evil' => 'x']],
            ['default']
        );

        self::assertSame(['greeting' => ['default' => 'Hi']], $result['labels']);
    }

    #[Test]
    public function englishIsNotOfferedAsTargetLanguage(): void
    {
        $locales = $this->createStub(Locales::class);
        $locales->method('getLanguages')->willReturn(['en' => 'English', 'de' => 'German', 'fr' => 'French']);
        $subject = new EditorViewDataProvider(
            $locales,
            new XliffUtility(),
            $this->createStub(ListUtility::class),
            $this->createStub(ExtensionAccessService::class),
            new XliffDocumentUpdater(),
            new ActiveLanguagesProvider()
        );

        self::assertSame(['de' => 'German', 'fr' => 'French'], $subject->getLanguageOptions());
    }
}

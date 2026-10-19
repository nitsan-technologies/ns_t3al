<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Tests\Unit\Backend\View;

use NITSAN\NsT3AL\Tests\Unit\Support\EditorViewDataProviderFactoryTrait;
use NITSAN\NsT3AL\Utility\XliffUtility;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Grid rows ($labels[key][lang]) → XLF units of one language file.
 */
final class EditorViewDataProviderLanguageFileTest extends UnitTestCase
{
    use EditorViewDataProviderFactoryTrait;

    private const LABELS = [
        'save' => ['default' => 'Save & close', 'de' => 'Speichern & schließen', 'fr' => ''],
        'open' => ['default' => 'Open', 'de' => '   ', 'fr' => 'Ouvrir'],
        '' => ['default' => 'Row without key', 'de' => 'Zeile ohne Schlüssel'],
    ];

    private const EXISTING_DE = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<xliff version="1.2" xmlns="urn:oasis:names:tc:xliff:document:1.2">
	<file source-language="en" target-language="de" datatype="plaintext" original="EXT:demo" product-name="demo">
		<header/>
		<body>
			<!-- translator note -->
			<trans-unit id="save" resname="save" approved="yes">
				<source>Save &amp; close</source>
				<target>Speichern</target>
			</trans-unit>
		</body>
	</file>
</xliff>

XML;

    private static function read(string $xml): array
    {
        return (new XliffUtility())->stringToArray($xml);
    }

    #[Test]
    public function newSourceFileContainsEnglishTextsWithoutTargets(): void
    {
        $result = $this->createEditorViewDataProvider()->buildLanguageFile('demo', 'locallang.xlf', 'default', self::LABELS);

        self::assertTrue($result['changed']);
        self::assertStringNotContainsString('<target', $result['content']);
        self::assertSame(
            ['save' => ['source' => 'Save & close', 'target' => null], 'open' => ['source' => 'Open', 'target' => null]],
            self::read($result['content'])
        );
    }

    #[Test]
    public function newTargetFileContainsTranslatedUnitsOnly(): void
    {
        $result = $this->createEditorViewDataProvider()->buildLanguageFile('demo', 'locallang.xlf', 'de', self::LABELS);

        self::assertStringContainsString('target-language="de"', $result['content']);
        self::assertSame(['save' => ['source' => 'Save & close', 'target' => 'Speichern & schließen']], self::read($result['content']));
    }

    #[Test]
    public function newTargetFileWithoutAnyTranslationIsNotCreated(): void
    {
        $labels = ['save' => ['default' => 'Save', 'it' => ''], 'open' => ['default' => 'Open', 'it' => ' ']];

        self::assertSame(
            ['content' => '', 'changed' => false],
            $this->createEditorViewDataProvider()->buildLanguageFile('demo', 'locallang.xlf', 'it', $labels)
        );
    }

    #[Test]
    public function newFileIsSortedByKeyOnRequest(): void
    {
        $result = $this->createEditorViewDataProvider()->buildLanguageFile('demo', 'locallang.xlf', 'fr', self::LABELS, [], true);
        $unsorted = $this->createEditorViewDataProvider()->buildLanguageFile('demo', 'locallang.xlf', 'default', self::LABELS);
        $sorted = $this->createEditorViewDataProvider()->buildLanguageFile('demo', 'locallang.xlf', 'default', self::LABELS, [], true);

        self::assertSame(['open'], array_keys(self::read($result['content'])));
        self::assertSame(['save', 'open'], array_keys(self::read($unsorted['content'])));
        self::assertSame(['open', 'save'], array_keys(self::read($sorted['content'])));
    }

    #[Test]
    public function existingFileIsUpdatedInPlace(): void
    {
        $provider = $this->createEditorViewDataProvider(['de' => self::EXISTING_DE]);
        $result = $provider->buildLanguageFile('demo', 'locallang.xlf', 'de', self::LABELS);

        self::assertTrue($result['changed']);
        self::assertStringContainsString('<!-- translator note -->', $result['content']);
        self::assertStringContainsString('<trans-unit id="save" resname="save" approved="yes">', $result['content']);
        self::assertSame(['save' => ['source' => 'Save & close', 'target' => 'Speichern & schließen']], self::read($result['content']));
    }

    #[Test]
    public function existingFileWithTheSameTranslationsIsNotRewritten(): void
    {
        $provider = $this->createEditorViewDataProvider(['de' => self::EXISTING_DE]);
        $labels = ['save' => ['default' => 'Save & close', 'de' => 'Speichern']];

        self::assertSame(
            ['content' => self::EXISTING_DE, 'changed' => false],
            $provider->buildLanguageFile('demo', 'locallang.xlf', 'de', $labels)
        );
    }

    #[Test]
    public function brokenExistingFileIsReportedInsteadOfOverwritten(): void
    {
        $provider = $this->createEditorViewDataProvider(['de' => '<xliff><file>']);

        self::assertNull($provider->buildLanguageFile('demo', 'locallang.xlf', 'de', self::LABELS));
    }
}

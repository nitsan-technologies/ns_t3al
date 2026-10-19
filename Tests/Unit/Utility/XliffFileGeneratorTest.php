<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Tests\Unit\Utility;

use NITSAN\NsT3AL\Utility\XliffDocumentUpdater;
use NITSAN\NsT3AL\Utility\XliffFileGenerator;
use NITSAN\NsT3AL\Utility\XliffUtility;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class XliffFileGeneratorTest extends UnitTestCase
{
    private const DATE_PATTERN = '/ date="\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z"/';

    #[Test]
    public function translationFileUsesTheTypo3CoreLayout(): void
    {
        $xml = (new XliffFileGenerator())->generate('demo', 'de', ['save' => ['source' => 'Save', 'target' => 'Speichern']], true, 'locallang.xlf');

        self::assertMatchesRegularExpression(self::DATE_PATTERN, $xml);
        self::assertSame(
            implode("\n", [
                '<?xml version="1.0" encoding="UTF-8"?>',
                '<xliff version="1.2" xmlns="urn:oasis:names:tc:xliff:document:1.2">',
                "\t" . '<file source-language="en" target-language="de" datatype="plaintext" original="EXT:demo/Resources/Private/Language/locallang.xlf" date="DATE" product-name="demo">',
                "\t\t<header/>",
                "\t\t<body>",
                "\t\t\t" . '<trans-unit id="save" resname="save">',
                "\t\t\t\t<source>Save</source>",
                "\t\t\t\t<target>Speichern</target>",
                "\t\t\t</trans-unit>",
                "\t\t</body>",
                "\t</file>",
                '</xliff>',
                '',
            ]),
            (string)preg_replace(self::DATE_PATTERN, ' date="DATE"', $xml)
        );
    }

    #[Test]
    public function newSourceFileHasNoUnitsAndNoTargetLanguage(): void
    {
        $xml = (new XliffFileGenerator())->generate('demo', 'en', [], false, 'test.xlf');

        self::assertStringContainsString("\t" . '<file source-language="en" datatype="plaintext" original="EXT:demo/Resources/Private/Language/test.xlf" date="', $xml);
        self::assertStringContainsString("\t\t<body>\n\t\t</body>", $xml);
        self::assertStringNotContainsString('trans-unit', $xml);
        self::assertSame([], (new XliffUtility())->stringToArray($xml));
    }

    #[Test]
    public function firstKeySavedIntoANewFileIsIndentedLikeTheRest(): void
    {
        $xml = (new XliffFileGenerator())->generate('demo', 'en', [], false, 'test.xlf');

        $result = (new XliffDocumentUpdater())->update($xml, ['first' => ['source' => 'First', 'target' => '']], [], false, false);

        self::assertTrue($result['changed']);
        self::assertStringContainsString(
            "\t\t<body>\n\t\t\t" . '<trans-unit id="first" resname="first">' . "\n\t\t\t\t<source>First</source>\n\t\t\t</trans-unit>\n\t\t</body>",
            $result['content']
        );
        self::assertSame(['first' => ['source' => 'First', 'target' => null]], (new XliffUtility())->stringToArray($result['content']));
    }

    #[Test]
    public function multiLineTextsAreNotReindented(): void
    {
        $xml = (new XliffFileGenerator())->generate('demo', 'de', ['help' => ['source' => "One\n  Two", 'target' => "Eins\n  Zwei"]], true);

        self::assertStringContainsString("<source>One\n  Two</source>", $xml);
        self::assertStringContainsString("<target>Eins\n  Zwei</target>", $xml);
    }

    #[Test]
    public function originalPointsToTheLanguageFolderWithoutFileName(): void
    {
        $xml = (new XliffFileGenerator())->generate('demo', 'de', [], true);

        self::assertStringContainsString('original="EXT:demo/Resources/Private/Language" ', $xml);
    }
}

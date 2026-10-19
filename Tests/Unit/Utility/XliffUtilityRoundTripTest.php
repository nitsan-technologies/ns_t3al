<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Tests\Unit\Utility;

use NITSAN\NsT3AL\Utility\XliffUtility;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class XliffUtilityRoundTripTest extends UnitTestCase
{
    private XliffUtility $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new XliffUtility();
    }

    /**
     * @return array<string, array{source: string, target: string}>
     */
    private static function units(): array
    {
        return [
            'plain' => ['source' => 'Save', 'target' => 'Speichern'],
            'ampersand' => ['source' => 'Save & close', 'target' => 'Speichern & schließen'],
            'markup' => ['source' => '<b>Bold</b> "double" \'single\' a > b', 'target' => '<b>Fett</b> „doppelt“ ‚einfach‘'],
            'unicode' => ['source' => 'Size – 日本語 😀', 'target' => 'Größe – 日本語 😀'],
            'multiline' => ['source' => "Line one\nLine two", 'target' => "Zeile eins\nZeile zwei"],
            'placeholders' => ['source' => '%s of %1$d items for {name}', 'target' => '%s von %1$d Elementen für {name}'],
            'tx.dotted.key' => ['source' => 'Dotted', 'target' => 'Gepunktet'],
            'empty' => ['source' => '', 'target' => ''],
        ];
    }

    #[Test]
    public function generatedTargetFileReadsBackTheSameTexts(): void
    {
        $xml = $this->subject->generateXml('demo', 'de', self::units());

        self::assertIsString($xml);
        self::assertSame(self::units(), $this->subject->stringToArray($xml));
    }

    #[Test]
    public function specialCharactersAreEscapedExactlyOnce(): void
    {
        $xml = (string)$this->subject->generateXml('demo', 'de', self::units());

        self::assertStringContainsString('<source>Save &amp; close</source>', $xml);
        self::assertStringContainsString('&lt;b&gt;Bold&lt;/b&gt;', $xml);
        self::assertStringNotContainsString('&amp;amp;', $xml);
    }

    #[Test]
    public function sourceFileHasNoTargetsAndNoTargetLanguage(): void
    {
        $xml = (string)$this->subject->generateXml('demo', 'en', ['save' => ['source' => 'Save', 'target' => 'ignored']], false);

        self::assertStringNotContainsString('<target', $xml);
        self::assertStringNotContainsString('target-language', $xml);
        self::assertSame(['save' => ['source' => 'Save', 'target' => null]], $this->subject->stringToArray($xml));
    }

    #[Test]
    public function targetFileHeaderDescribesTheExtensionAndLanguage(): void
    {
        $document = new \DOMDocument();
        $document->loadXML((string)$this->subject->generateXml('demo', 'de', ['save' => ['source' => 'Save', 'target' => 'Speichern']]));
        $xliff = $document->documentElement;
        $file = $xliff->getElementsByTagName('file')->item(0);
        $unit = $xliff->getElementsByTagName('trans-unit')->item(0);

        self::assertSame('1.2', $xliff->getAttribute('version'));
        self::assertSame('urn:oasis:names:tc:xliff:document:1.2', $xliff->namespaceURI);
        self::assertSame('en', $file->getAttribute('source-language'));
        self::assertSame('de', $file->getAttribute('target-language'));
        self::assertSame('EXT:demo/Resources/Private/Language', $file->getAttribute('original'));
        self::assertSame('demo', $file->getAttribute('product-name'));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $file->getAttribute('date'));
        self::assertSame('save', $unit->getAttribute('id'));
        self::assertSame('save', $unit->getAttribute('resname'));
    }

    #[Test]
    public function singleUnitFileIsRead(): void
    {
        $xml = (string)$this->subject->generateXml('demo', 'de', ['only' => ['source' => 'Only', 'target' => 'Einzig']]);

        self::assertSame(['only' => ['source' => 'Only', 'target' => 'Einzig']], $this->subject->stringToArray($xml));
    }

    #[Test]
    public function fileWithoutUnitsReadsAsEmpty(): void
    {
        self::assertSame([], $this->subject->stringToArray((string)$this->subject->generateXml('demo', 'de', [])));
    }

    #[Test]
    public function surroundingWhitespaceIsTrimmedOnRead(): void
    {
        $xml = (string)$this->subject->generateXml('demo', 'de', ['padded' => ['source' => "  Padded \n", 'target' => ' Gepolstert ']]);

        self::assertSame(['padded' => ['source' => 'Padded', 'target' => 'Gepolstert']], $this->subject->stringToArray($xml));
    }

    #[Test]
    public function targetWithAttributesIsReadAsText(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<xliff version="1.2" xmlns="urn:oasis:names:tc:xliff:document:1.2">
	<file source-language="en" target-language="de" datatype="plaintext" original="EXT:demo">
		<body>
			<trans-unit id="a" approved="yes">
				<source>A</source>
				<target state="translated">A de</target>
			</trans-unit>
			<trans-unit id="b">
				<source>B</source>
				<target xml:space="preserve">B de</target>
			</trans-unit>
		</body>
	</file>
</xliff>
XML;

        self::assertSame(
            ['a' => ['source' => 'A', 'target' => 'A de'], 'b' => ['source' => 'B', 'target' => 'B de']],
            $this->subject->stringToArray($xml)
        );
    }

    #[Test]
    public function invalidXmlThrows(): void
    {
        $this->expectException(\Exception::class);
        $this->subject->stringToArray('<xliff><file>');
    }
}

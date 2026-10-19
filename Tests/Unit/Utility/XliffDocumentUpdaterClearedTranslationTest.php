<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Tests\Unit\Utility;

use NITSAN\NsT3AL\Utility\XliffDocumentUpdater;
use NITSAN\NsT3AL\Utility\XliffUtility;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * TYPO3 shows English only for keys missing from a translation file; a unit left without target text
 * renders an empty label. So a cleared translation must take its whole unit out of the file.
 */
final class XliffDocumentUpdaterClearedTranslationTest extends UnitTestCase
{
    private const TARGET_FILE = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<xliff version="1.2" xmlns="urn:oasis:names:tc:xliff:document:1.2">
	<file source-language="en" target-language="de" datatype="plaintext" original="EXT:demo/Resources/Private/Language/locallang.xlf" date="2024-01-01T00:00:00Z" product-name="demo">
		<header/>
		<body>
			<!-- keep me -->
			<trans-unit id="alpha" resname="alpha" approved="yes">
				<source>Alpha</source>
				<target>Alpha DE</target>
			</trans-unit>
			<trans-unit id="beta" resname="beta">
				<source>Beta</source>
				<target>Beta DE</target>
			</trans-unit>
			<trans-unit id="gamma" resname="gamma">
				<source>Gamma</source>
			</trans-unit>
			<trans-unit id="delta" resname="delta">
				<source>Delta</source>
				<target></target>
			</trans-unit>
		</body>
	</file>
</xliff>

XML;

    private XliffDocumentUpdater $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new XliffDocumentUpdater();
    }

    /**
     * @return array<string, array{source: string, target: string}>
     */
    private static function gridUnits(array $overrides = []): array
    {
        return array_replace([
            'alpha' => ['source' => 'Alpha', 'target' => 'Alpha DE'],
            'beta' => ['source' => 'Beta', 'target' => 'Beta DE'],
            'gamma' => ['source' => 'Gamma', 'target' => ''],
            'delta' => ['source' => 'Delta', 'target' => ''],
        ], $overrides);
    }

    private static function unitIds(string $xml): array
    {
        preg_match_all('/<trans-unit id="([^"]+)"/', $xml, $matches);
        return $matches[1];
    }

    #[Test]
    public function clearedTranslationRemovesItsUnit(): void
    {
        $result = $this->subject->update(self::TARGET_FILE, self::gridUnits(['alpha' => ['source' => 'Alpha', 'target' => '']]), [], true, false);

        self::assertTrue($result['changed']);
        self::assertSame(['beta', 'gamma', 'delta'], self::unitIds($result['content']));
        self::assertSame(str_replace("\t\t\t<trans-unit id=\"alpha\" resname=\"alpha\" approved=\"yes\">\n\t\t\t\t<source>Alpha</source>\n\t\t\t\t<target>Alpha DE</target>\n\t\t\t</trans-unit>\n", '', self::TARGET_FILE), $result['content']);
        self::assertArrayNotHasKey('alpha', (new XliffUtility())->stringToArray($result['content']));
    }

    #[Test]
    public function whitespaceOnlyValueCountsAsCleared(): void
    {
        $result = $this->subject->update(self::TARGET_FILE, self::gridUnits(['beta' => ['source' => 'Beta', 'target' => " \t "]]), [], true, false);

        self::assertSame(['alpha', 'gamma', 'delta'], self::unitIds($result['content']));
    }

    #[Test]
    public function untouchedUnitsWithoutTargetTextAreKept(): void
    {
        self::assertSame(['content' => self::TARGET_FILE, 'changed' => false], $this->subject->update(self::TARGET_FILE, self::gridUnits(), [], true, false));
    }

    #[Test]
    public function earlierDuplicatesOfAClearedUnitAreRemovedToo(): void
    {
        $xml = str_replace("\t\t\t<!-- keep me -->\n", "\t\t\t<!-- keep me -->\n\t\t\t<trans-unit id=\"beta\" resname=\"beta\">\n\t\t\t\t<source>Beta</source>\n\t\t\t\t<target>Beta alt</target>\n\t\t\t</trans-unit>\n", self::TARGET_FILE);
        $result = $this->subject->update($xml, self::gridUnits(['beta' => ['source' => 'Beta', 'target' => '']]), [], true, false);

        self::assertSame(['alpha', 'gamma', 'delta'], self::unitIds($result['content']));
        self::assertStringNotContainsString('Beta alt', $result['content']);
    }

    #[Test]
    public function renamedAndClearedUnitIsRemoved(): void
    {
        $grid = ['alpha_new' => ['source' => 'Alpha', 'target' => '']] + self::gridUnits();
        unset($grid['alpha']);
        $result = $this->subject->update(self::TARGET_FILE, $grid, ['alpha' => 'alpha_new'], true, false);

        self::assertSame(['beta', 'gamma', 'delta'], self::unitIds($result['content']));
    }

    #[Test]
    public function sourceFilesKeepUnitsWhateverTheTargetValue(): void
    {
        $result = $this->subject->update(self::TARGET_FILE, self::gridUnits(['alpha' => ['source' => 'Alpha', 'target' => '']]), [], false, false);

        self::assertSame(['content' => self::TARGET_FILE, 'changed' => false], $result);
    }
}

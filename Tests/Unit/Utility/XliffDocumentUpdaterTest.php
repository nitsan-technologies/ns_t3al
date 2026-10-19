<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Tests\Unit\Utility;

use NITSAN\NsT3AL\Utility\XliffDocumentUpdater;
use NITSAN\NsT3AL\Utility\XliffUtility;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class XliffDocumentUpdaterTest extends UnitTestCase
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
				<source>Save &amp; close</source>
				<target state="translated">Speichern &amp; schließen</target>
			</trans-unit>
			<trans-unit id="gamma" resname="gamma">
				<source>Gamma</source>
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
            'beta' => ['source' => 'Save & close', 'target' => 'Speichern & schließen'],
            'gamma' => ['source' => 'Gamma', 'target' => ''],
        ], $overrides);
    }

    /**
     * @return list<int> 0-based indexes of the lines that differ (both texts must have the same line count)
     */
    private static function changedLines(string $before, string $after): array
    {
        $beforeLines = explode("\n", $before);
        $afterLines = explode("\n", $after);
        self::assertCount(count($beforeLines), $afterLines);
        return array_keys(array_diff_assoc($beforeLines, $afterLines));
    }

    private static function unitIds(string $xml): array
    {
        preg_match_all('/<trans-unit id="([^"]+)"/', $xml, $matches);
        return $matches[1];
    }

    #[Test]
    public function unchangedGridKeepsTheFileByteIdentical(): void
    {
        $result = $this->subject->update(self::TARGET_FILE, self::gridUnits(), [], true, false);

        self::assertSame(['content' => self::TARGET_FILE, 'changed' => false], $result);
    }

    #[Test]
    public function editingOneTranslationChangesOnlyThatLine(): void
    {
        $result = $this->subject->update(self::TARGET_FILE, self::gridUnits(['alpha' => ['source' => 'Alpha', 'target' => 'Alpha neu']]), [], true, false);

        self::assertTrue($result['changed']);
        self::assertSame([8], self::changedLines(self::TARGET_FILE, $result['content']));
        self::assertStringContainsString("\t\t\t\t<target>Alpha neu</target>", $result['content']);
    }

    #[Test]
    public function savedFileReadsBackAsTheGrid(): void
    {
        $grid = self::gridUnits(['beta' => ['source' => 'Save & close', 'target' => 'Sichern & <schließen>']]);
        $result = $this->subject->update(self::TARGET_FILE, $grid, [], true, false);

        self::assertStringContainsString('Sichern &amp; &lt;schließen&gt;', $result['content']);
        self::assertSame(
            ['alpha' => ['source' => 'Alpha', 'target' => 'Alpha DE'], 'beta' => ['source' => 'Save & close', 'target' => 'Sichern & <schließen>'], 'gamma' => ['source' => 'Gamma', 'target' => null]],
            (new XliffUtility())->stringToArray($result['content'])
        );
    }

    #[Test]
    public function renamedKeyKeepsItsPositionAndAttributes(): void
    {
        $grid = ['alpha_new' => self::gridUnits()['alpha']] + self::gridUnits();
        unset($grid['alpha']);
        $result = $this->subject->update(self::TARGET_FILE, $grid, ['alpha' => 'alpha_new'], true, false);

        self::assertTrue($result['changed']);
        self::assertSame(['alpha_new', 'beta', 'gamma'], self::unitIds($result['content']));
        self::assertStringContainsString('<trans-unit id="alpha_new" resname="alpha_new" approved="yes">', $result['content']);
    }

    #[Test]
    public function removedKeyIsDeletedWithoutLeavingBlankLines(): void
    {
        $grid = self::gridUnits();
        unset($grid['beta']);
        $result = $this->subject->update(self::TARGET_FILE, $grid, [], true, false);

        self::assertSame(['alpha', 'gamma'], self::unitIds($result['content']));
        self::assertSame(substr_count(self::TARGET_FILE, "\n") - 4, substr_count($result['content'], "\n"));
        self::assertStringNotContainsString("\n\n", rtrim($result['content']));
    }

    #[Test]
    public function newTranslatedKeyIsAppendedWithTheFileIndentation(): void
    {
        $grid = self::gridUnits(['delta' => ['source' => 'Delta', 'target' => 'Delta DE']]);
        $result = $this->subject->update(self::TARGET_FILE, $grid, [], true, false);

        self::assertStringContainsString(
            "\t\t\t</trans-unit>\n\t\t\t<trans-unit id=\"delta\" resname=\"delta\">\n\t\t\t\t<source>Delta</source>\n\t\t\t\t<target>Delta DE</target>\n\t\t\t</trans-unit>\n\t\t</body>",
            $result['content']
        );
    }

    #[Test]
    public function emptyTranslationsNeverAddTargetElements(): void
    {
        $grid = self::gridUnits(['delta' => ['source' => 'Delta', 'target' => '  ']]);
        $result = $this->subject->update(self::TARGET_FILE, $grid, [], true, false);

        self::assertSame(['content' => self::TARGET_FILE, 'changed' => false], $result);
    }

    #[Test]
    public function targetFileSourceIsOnlyRefreshedTogetherWithItsTranslation(): void
    {
        $sourceOnly = $this->subject->update(self::TARGET_FILE, self::gridUnits(['alpha' => ['source' => 'Alpha v2', 'target' => 'Alpha DE']]), [], true, false);
        $withTarget = $this->subject->update(self::TARGET_FILE, self::gridUnits(['alpha' => ['source' => 'Alpha v2', 'target' => 'Alpha DE v2']]), [], true, false);

        self::assertFalse($sourceOnly['changed']);
        self::assertSame([7, 8], self::changedLines(self::TARGET_FILE, $withTarget['content']));
    }

    #[Test]
    public function sourceFileUpdatesOnlySources(): void
    {
        $result = $this->subject->update(self::TARGET_FILE, self::gridUnits(['alpha' => ['source' => 'Alpha v2', 'target' => 'ignored']]), [], false, false);

        self::assertSame([7], self::changedLines(self::TARGET_FILE, $result['content']));
    }

    #[Test]
    public function sortByKeyOrdersUnitsLikeTheGrid(): void
    {
        $grid = self::gridUnits() + ['zeta' => self::gridUnits()['alpha']];
        unset($grid['alpha']);
        $result = $this->subject->update(self::TARGET_FILE, $grid, ['alpha' => 'zeta'], true, true);

        self::assertSame(['beta', 'gamma', 'zeta'], self::unitIds($result['content']));
    }

    #[Test]
    public function movedRowsAreWrittenInGridOrderOnlyWhenRequested(): void
    {
        $units = self::gridUnits();
        $moved = ['gamma' => $units['gamma'], 'alpha' => $units['alpha'], 'beta' => $units['beta']];

        $ignored = $this->subject->update(self::TARGET_FILE, $moved, [], true, false);
        $kept = $this->subject->update(self::TARGET_FILE, $moved, [], true, false, true);

        self::assertSame(['content' => self::TARGET_FILE, 'changed' => false], $ignored);
        self::assertTrue($kept['changed']);
        self::assertSame(['gamma', 'alpha', 'beta'], self::unitIds($kept['content']));
        self::assertSame(
            $this->subject->update(self::TARGET_FILE, self::gridUnits(), [], true, false)['content'],
            $this->subject->update($kept['content'], self::gridUnits(), [], true, false, true)['content'],
            'Moving the rows back restores the original file'
        );
    }

    #[Test]
    public function gridOrderKeepsUnitContentCommentsAndIndentation(): void
    {
        $units = self::gridUnits();
        $result = $this->subject->update(self::TARGET_FILE, ['beta' => $units['beta'], 'alpha' => $units['alpha'], 'gamma' => $units['gamma']], [], true, false, true);
        $content = $result['content'];

        self::assertSame(['beta', 'alpha', 'gamma'], self::unitIds($content));
        self::assertStringContainsString("\t\t\t<!-- keep me -->\n", $content);
        self::assertStringContainsString("\t\t\t<trans-unit id=\"alpha\" resname=\"alpha\" approved=\"yes\">\n\t\t\t\t<source>Alpha</source>\n\t\t\t\t<target>Alpha DE</target>\n\t\t\t</trans-unit>", $content);
        self::assertStringContainsString('<target state="translated">Speichern &amp; schließen</target>', $content);
        self::assertSame(substr_count(self::TARGET_FILE, "\n"), substr_count($content, "\n"));
    }

    #[Test]
    public function movingOneUnitChangesOnlyItsLinesInFilesWithMixedIndentation(): void
    {
        $xml = str_replace(
            ["\t\t\t<trans-unit id=\"beta\"", "\t\t\t<trans-unit id=\"gamma\""],
            ['            <trans-unit id="beta"', "\n\t\t\t<trans-unit id=\"gamma\""],
            self::TARGET_FILE
        );
        $units = self::gridUnits();
        $result = $this->subject->update($xml, ['alpha' => $units['alpha'], 'gamma' => $units['gamma'], 'beta' => $units['beta']], [], true, false, true);

        $expected = str_replace(
            "            <trans-unit id=\"beta\" resname=\"beta\">\n\t\t\t\t<source>Save &amp; close</source>\n\t\t\t\t<target state=\"translated\">Speichern &amp; schließen</target>\n\t\t\t</trans-unit>\n\n\t\t\t<trans-unit id=\"gamma\" resname=\"gamma\">\n\t\t\t\t<source>Gamma</source>\n\t\t\t</trans-unit>",
            "\n\t\t\t<trans-unit id=\"gamma\" resname=\"gamma\">\n\t\t\t\t<source>Gamma</source>\n\t\t\t</trans-unit>\n            <trans-unit id=\"beta\" resname=\"beta\">\n\t\t\t\t<source>Save &amp; close</source>\n\t\t\t\t<target state=\"translated\">Speichern &amp; schließen</target>\n\t\t\t</trans-unit>",
            $xml
        );
        self::assertSame($expected, $result['content']);
    }

    #[Test]
    public function gridOrderAppliesToTheUnitsATranslationFileHas(): void
    {
        $units = self::gridUnits();
        $grid = ['gamma' => $units['gamma'], 'new' => ['source' => 'New', 'target' => 'Neu'], 'beta' => $units['beta'], 'alpha' => $units['alpha']];
        $result = $this->subject->update(self::TARGET_FILE, $grid, [], true, false, true);

        self::assertSame(['gamma', 'new', 'beta', 'alpha'], self::unitIds($result['content']));
    }

    #[Test]
    public function sortByKeyWinsOverGridOrder(): void
    {
        $units = self::gridUnits();
        $result = $this->subject->update(self::TARGET_FILE, ['gamma' => $units['gamma'], 'beta' => $units['beta'], 'alpha' => $units['alpha']], [], true, true, true);

        self::assertSame(['alpha', 'beta', 'gamma'], self::unitIds($result['content']));
        self::assertFalse($result['changed']);
    }

    #[Test]
    public function windowsLineEndingsArePreserved(): void
    {
        $crlf = str_replace("\n", "\r\n", self::TARGET_FILE);
        $result = $this->subject->update($crlf, self::gridUnits(['alpha' => ['source' => 'Alpha', 'target' => 'Alpha neu']]), [], true, false);

        self::assertStringContainsString("<target>Alpha neu</target>\r\n", $result['content']);
        self::assertSame(0, preg_match('/(?<!\r)\n/', $result['content']));
    }

    #[Test]
    public function unparsableOrBodylessDocumentsAreNotTouched(): void
    {
        self::assertNull($this->subject->update('<xliff><file>', self::gridUnits(), [], true, false));
        self::assertNull($this->subject->update('<?xml version="1.0"?><xliff version="1.2"><file/></xliff>', self::gridUnits(), [], true, false));
    }
}

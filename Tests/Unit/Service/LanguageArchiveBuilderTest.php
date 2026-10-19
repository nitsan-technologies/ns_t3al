<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Tests\Unit\Service;

use NITSAN\NsT3AL\Service\LanguageArchiveBuilder;
use NITSAN\NsT3AL\Tests\Unit\Support\TemporaryDirectoryTrait;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class LanguageArchiveBuilderTest extends UnitTestCase
{
    use TemporaryDirectoryTrait;

    private string $languageDirectory;

    /** @var list<string> */
    private array $archives = [];

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TYPO3_CONF_VARS']['EXT']['excludeForPackaging'] = '(?:\\.(?!htaccess$).*|.*~|.*\\.swp|.*\\.bak|node_modules|bower_components)';
        $this->languageDirectory = $this->createTemporaryDirectory() . '/Resources/Private/Language';
        $this->writeFile($this->languageDirectory . '/locallang.xlf', '<xliff>en</xliff>');
        $this->writeFile($this->languageDirectory . '/de.locallang.xlf', '<xliff>de</xliff>');
        $this->writeFile($this->languageDirectory . '/Form/locallang_form.xlf', '<xliff>form</xliff>');
        $this->writeFile($this->languageDirectory . '/Form/Deep/de.locallang_deep.xlf', '<xliff>deep</xliff>');
        $this->writeFile($this->languageDirectory . '/.DS_Store', 'ignored');
        $this->writeFile($this->languageDirectory . '/locallang.xlf~', 'ignored');
    }

    protected function tearDown(): void
    {
        foreach ($this->archives as $archive) {
            if (is_file($archive)) {
                unlink($archive);
            }
        }
        $this->removeTemporaryDirectory();
        parent::tearDown();
    }

    #[Test]
    public function archiveContainsSubFoldersWithRelativeEntryNames(): void
    {
        $entries = $this->readEntries($this->build($this->languageDirectory));

        self::assertSame(
            [
                'Form/',
                'Form/Deep/',
                'Form/Deep/de.locallang_deep.xlf',
                'Form/locallang_form.xlf',
                'de.locallang.xlf',
                'locallang.xlf',
            ],
            array_keys($entries)
        );
        self::assertSame('<xliff>deep</xliff>', $entries['Form/Deep/de.locallang_deep.xlf']);
        self::assertSame('<xliff>de</xliff>', $entries['de.locallang.xlf']);
    }

    #[Test]
    public function trailingSlashOfTheDirectoryDoesNotChangeTheEntries(): void
    {
        self::assertSame(
            array_keys($this->readEntries($this->build($this->languageDirectory))),
            array_keys($this->readEntries($this->build($this->languageDirectory . '/')))
        );
    }

    #[Test]
    public function everyBuildWritesItsOwnArchive(): void
    {
        $first = $this->build($this->languageDirectory);
        $second = $this->build($this->languageDirectory);

        self::assertNotSame($first, $second);
        self::assertCount(6, $this->readEntries($first));
        self::assertCount(6, $this->readEntries($second));
    }

    #[Test]
    public function directoryWithoutFilesIsRejected(): void
    {
        $emptyDirectory = $this->temporaryDirectory . '/Empty/Sub';
        mkdir($emptyDirectory, 0777, true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1791300001);
        $this->build($this->temporaryDirectory . '/Empty');
    }

    #[Test]
    public function missingDirectoryIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1791300001);
        $this->build($this->temporaryDirectory . '/does-not-exist');
    }

    private function build(string $directory): string
    {
        $archive = (new LanguageArchiveBuilder())->build($directory);
        $this->archives[] = $archive;
        return $archive;
    }

    /**
     * @return array<string, string> Entry name => content, sorted by name
     */
    private function readEntries(string $archivePath): array
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($archivePath, \ZipArchive::RDONLY));
        $entries = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string)$zip->getNameIndex($index);
            $entries[$name] = str_ends_with($name, '/') ? '' : (string)$zip->getFromIndex($index);
        }
        $zip->close();
        ksort($entries);
        return $entries;
    }
}

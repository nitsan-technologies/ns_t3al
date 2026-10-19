<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Tests\Unit\Utility;

use NITSAN\NsT3AL\Tests\Unit\Support\TemporaryDirectoryTrait;
use NITSAN\NsT3AL\Utility\XliffUtility;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class XliffUtilityFileListTest extends UnitTestCase
{
    use TemporaryDirectoryTrait;

    private string $packagePath;
    private string $languageDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->packagePath = $this->createTemporaryDirectory() . '/package/';
        $this->languageDirectory = $this->packagePath . XliffUtility::LANGUAGE_DIR;
        foreach ([
            'locallang.xlf',
            'de.locallang.xlf',
            'fr.locallang.xlf',
            'locallang_mod.xlf',
            'de.orphan.xlf',
            'readme.txt',
            'x.y.z.xlf',
            'bad name.xlf',
            'Sub/nested.xlf',
        ] as $file) {
            $this->writeFile($this->languageDirectory . '/' . $file, '<xliff/>');
        }
    }

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
        parent::tearDown();
    }

    #[Test]
    public function languageFileIndexListsLanguagesPerBaseFileWithDefaultFirst(): void
    {
        self::assertSame(
            [
                'locallang.xlf' => ['default', 'de', 'fr'],
                'locallang_mod.xlf' => ['default'],
                'orphan.xlf' => ['de'],
            ],
            XliffUtility::getLanguageFileIndex($this->languageDirectory)
        );
    }

    #[Test]
    public function languageFileIndexOfAMissingDirectoryIsEmpty(): void
    {
        self::assertSame([], XliffUtility::getLanguageFileIndex($this->temporaryDirectory . '/missing'));
    }

    #[Test]
    public function fileListContainsOnlyEditableSourceFiles(): void
    {
        self::assertSame(
            [
                'locallang.xlf' => 'locallang.xlf',
                'locallang_mod.xlf' => 'locallang_mod.xlf',
            ],
            XliffUtility::getFileList($this->packagePath)
        );
    }

    #[Test]
    public function everyListedFileIsAcceptedByTheFileNameValidation(): void
    {
        foreach (XliffUtility::getFileList($this->packagePath) as $fileName) {
            self::assertTrue(XliffUtility::isValidXliffFileName($fileName), $fileName);
        }
    }

    #[Test]
    public function fileListOfAPackageWithoutLanguageDirectoryIsEmpty(): void
    {
        self::assertSame([], XliffUtility::getFileList($this->temporaryDirectory . '/no-package/'));
    }
}

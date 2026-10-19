<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Tests\Unit\Utility;

use NITSAN\NsT3AL\Tests\Unit\Support\PathResolvingXliffUtility;
use NITSAN\NsT3AL\Tests\Unit\Support\TemporaryDirectoryTrait;
use NITSAN\NsT3AL\Utility\XliffUtility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class XliffUtilityPathTest extends UnitTestCase
{
    use TemporaryDirectoryTrait;

    private string $packagePath;
    private string $languageDirectory;
    private PathResolvingXliffUtility $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->packagePath = $this->createTemporaryDirectory() . '/package/';
        $this->languageDirectory = $this->packagePath . XliffUtility::LANGUAGE_DIR;
        mkdir($this->languageDirectory, 0777, true);
        $this->subject = new PathResolvingXliffUtility();
    }

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
        parent::tearDown();
    }

    public static function validFileNames(): array
    {
        return [
            'plain' => ['locallang.xlf'],
            'underscore' => ['locallang_mod.xlf'],
            'dash and digits' => ['labels-be2.xlf'],
        ];
    }

    public static function invalidFileNames(): array
    {
        return [
            'empty' => [''],
            'no base name' => ['.xlf'],
            'parent directory' => ['../locallang.xlf'],
            'double dot only' => ['..xlf'],
            'sub directory' => ['sub/locallang.xlf'],
            'backslash' => ['sub\\locallang.xlf'],
            'absolute path' => ['/etc/passwd'],
            'wrong suffix' => ['locallang.xml'],
            'double suffix' => ['locallang.xlf.php'],
            'language prefixed' => ['de.locallang.xlf'],
            'space' => ['local lang.xlf'],
            'trailing newline' => ["locallang.xlf\n"],
            'null byte' => ["locallang.xlf\0"],
            'non ascii' => ['läbels.xlf'],
        ];
    }

    public static function validLanguageCodes(): array
    {
        return [
            'default' => ['default'],
            'iso' => ['de'],
            'region underscore' => ['pt_BR'],
            'region dash' => ['de-CH'],
        ];
    }

    public static function invalidLanguageCodes(): array
    {
        return [
            'empty' => [''],
            'parent directory' => ['..'],
            'traversal prefix' => ['../de'],
            'traversal suffix' => ['de/..'],
            'slash' => ['de/x'],
            'backslash' => ['de\\x'],
            'dot' => ['de.x'],
            'space' => ['d e'],
            'trailing newline' => ["de\n"],
            'null byte' => ["de\0"],
        ];
    }

    #[Test]
    #[DataProvider('validFileNames')]
    public function fileNameAllowlistAcceptsPlainXliffBaseNames(string $fileName): void
    {
        self::assertTrue(XliffUtility::isValidXliffFileName($fileName));
    }

    #[Test]
    #[DataProvider('invalidFileNames')]
    public function fileNameAllowlistRejectsPathsAndOtherNames(string $fileName): void
    {
        self::assertFalse(XliffUtility::isValidXliffFileName($fileName));
    }

    #[Test]
    #[DataProvider('validLanguageCodes')]
    public function languageCodeAllowlistAcceptsLanguageKeys(string $languageCode): void
    {
        self::assertTrue(XliffUtility::isValidLanguageCode($languageCode));
    }

    #[Test]
    #[DataProvider('invalidLanguageCodes')]
    public function languageCodeAllowlistRejectsPathCharacters(string $languageCode): void
    {
        self::assertFalse(XliffUtility::isValidLanguageCode($languageCode));
    }

    #[Test]
    public function defaultLanguageResolvesToTheSourceFile(): void
    {
        self::assertSame(
            $this->languageDirectory . '/locallang.xlf',
            $this->subject->resolve('locallang.xlf', 'default', $this->packagePath)
        );
    }

    #[Test]
    public function targetLanguageResolvesToThePrefixedFile(): void
    {
        self::assertSame(
            $this->languageDirectory . '/de.locallang.xlf',
            $this->subject->resolve('locallang.xlf', 'de', rtrim($this->packagePath, '/'))
        );
    }

    #[Test]
    #[DataProvider('invalidFileNames')]
    public function invalidFileNameIsRejectedBeforeAnyPathIsBuilt(string $fileName): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1740000001);
        $this->subject->resolve($fileName, 'de', $this->packagePath);
    }

    #[Test]
    #[DataProvider('invalidLanguageCodes')]
    public function invalidLanguageCodeIsRejectedBeforeAnyPathIsBuilt(string $languageCode): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1740000002);
        $this->subject->resolve('locallang.xlf', $languageCode, $this->packagePath);
    }

    #[Test]
    public function missingLanguageDirectoryIsAllowedForTheFirstWrite(): void
    {
        $packagePath = $this->temporaryDirectory . '/empty-package/';
        self::assertSame(
            $packagePath . XliffUtility::LANGUAGE_DIR . '/fr.locallang.xlf',
            $this->subject->resolve('locallang.xlf', 'fr', $packagePath)
        );
    }

    #[Test]
    public function fileSymlinkPointingOutsideTheLanguageDirectoryIsRejected(): void
    {
        $outside = $this->temporaryDirectory . '/outside.xlf';
        $this->writeFile($outside, '<xliff/>');
        symlink($outside, $this->languageDirectory . '/de.locallang.xlf');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1740000006);
        $this->subject->resolve('locallang.xlf', 'de', $this->packagePath);
    }

    #[Test]
    public function symlinkedLanguageDirectoryIsAllowed(): void
    {
        $realLanguageDirectory = $this->temporaryDirectory . '/shared-language';
        mkdir($realLanguageDirectory);
        $linkedPackage = $this->temporaryDirectory . '/linked-package/';
        mkdir($linkedPackage . 'Resources/Private', 0777, true);
        symlink($realLanguageDirectory, $linkedPackage . XliffUtility::LANGUAGE_DIR);

        self::assertSame(
            $linkedPackage . XliffUtility::LANGUAGE_DIR . '/locallang.xlf',
            $this->subject->resolve('locallang.xlf', 'default', $linkedPackage)
        );
    }

    #[Test]
    public function publicFileApiFailsSafelyOnTraversal(): void
    {
        $utility = new XliffUtility();
        $outside = $this->temporaryDirectory . '/package/Resources/Private/secret.xlf';

        self::assertSame('', $utility->getFilePath('demo', '../secret.xlf', 'default', $this->packagePath));
        self::assertSame('', $utility->readFile('demo', 'locallang.xlf', '../de', $this->packagePath));
        self::assertFalse($utility->writeFile('demo', '<xliff/>', '../secret.xlf', 'default', $this->packagePath));
        self::assertFileDoesNotExist($outside);
    }

    #[Test]
    public function writeAndReadUseTheResolvedLanguageFile(): void
    {
        $utility = new XliffUtility();

        self::assertSame('', $utility->readFile('demo', 'locallang.xlf', 'de', $this->packagePath));
        self::assertTrue($utility->writeFile('demo', '<xliff version="1.2"/>', 'locallang.xlf', 'de', $this->packagePath));
        self::assertFileExists($this->languageDirectory . '/de.locallang.xlf');
        self::assertSame('<xliff version="1.2"/>', $utility->readFile('demo', 'locallang.xlf', 'de', $this->packagePath));
    }

    #[Test]
    public function firstWriteCreatesTheMissingLanguageDirectory(): void
    {
        $utility = new XliffUtility();
        rmdir($this->languageDirectory);

        self::assertTrue($utility->writeFile('demo', '<xliff version="1.2"/>', 'locallang.xlf', 'de', $this->packagePath));
        self::assertFileExists($this->languageDirectory . '/de.locallang.xlf');
    }
}

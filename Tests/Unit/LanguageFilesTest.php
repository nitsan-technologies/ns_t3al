<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * A key missing from locallang.xlf renders a blank notice; a key missing from a translation file (or one
 * without <target> text) renders English resp. an empty label for that backend language.
 */
final class LanguageFilesTest extends UnitTestCase
{
    private const EXTENSION_PATH = __DIR__ . '/../../';

    private const LANGUAGE_DIR = self::EXTENSION_PATH . 'Resources/Private/Language/';

    /**
     * Lookup helper (PHP) or call pattern (Fluid, JS) => label file it reads.
     */
    private const LOOKUPS = [
        '/\b(?:translate|translateProviderLabel)\(\s*\'([\w.\-]+)\'/' => 'locallang.xlf',
        '/\btranslateMod\(\s*\'([\w.\-]+)\'/' => 'locallang_mod.xlf',
        '/f:translate\(\s*key:\s*\'([\w.\-]+)\'/' => 'locallang.xlf',
        '/<f:translate[^>]*\bkey="([\w.\-]+)"/' => 'locallang.xlf',
    ];

    #[Test]
    public function translationFilesHaveEveryKeyOfTheirEnglishFileWithATarget(): void
    {
        $translationFiles = glob(self::LANGUAGE_DIR . '[a-z][a-z].*.xlf') ?: [];
        self::assertNotSame([], $translationFiles);
        foreach ($translationFiles as $translationFile) {
            $name = basename($translationFile);
            $englishKeys = array_keys($this->units(self::LANGUAGE_DIR . substr($name, 3)));
            $units = $this->units($translationFile);

            self::assertSame([], array_values(array_diff($englishKeys, array_keys($units))), $name . ': keys missing');
            self::assertSame([], array_values(array_diff(array_keys($units), $englishKeys)), $name . ': keys not in the English file');
            self::assertSame([], array_keys(array_filter($units, static fn (string $target): bool => trim($target) === '')), $name . ': units without <target> text');
        }
    }

    /**
     * Labels are passed through sprintf(); a target with fewer or other placeholders than its source
     * throws (ArgumentCountError / ValueError) instead of showing the message in that backend language.
     */
    #[Test]
    public function translationTargetsKeepThePlaceholdersOfTheirSource(): void
    {
        $mismatches = [];
        foreach (glob(self::LANGUAGE_DIR . '[a-z][a-z].*.xlf') ?: [] as $translationFile) {
            $englishUnits = $this->units(self::LANGUAGE_DIR . substr(basename($translationFile), 3));
            foreach ($this->units($translationFile) as $key => $target) {
                if (isset($englishUnits[$key]) && $this->placeholders($target) !== $this->placeholders($englishUnits[$key])) {
                    $mismatches[] = basename($translationFile) . ':' . $key;
                }
            }
        }
        self::assertSame([], $mismatches);
    }

    #[Test]
    public function labelFilesHaveNoDuplicateIds(): void
    {
        foreach (glob(self::LANGUAGE_DIR . '*.xlf') ?: [] as $file) {
            preg_match_all('/<trans-unit\b[^>]*\bid="([^"]+)"/', (string)file_get_contents($file), $matches);
            $duplicates = array_keys(array_filter(array_count_values($matches[1]), static fn (int $count): bool => $count > 1));
            self::assertSame([], $duplicates, basename($file) . ': duplicate trans-unit ids (the last one wins)');
        }
    }

    #[Test]
    public function everyReferencedLabelKeyExists(): void
    {
        $missing = [];
        foreach ($this->sourceFiles() as $path) {
            $content = (string)file_get_contents($path);
            $references = [];
            foreach (self::LOOKUPS as $pattern => $labelFile) {
                preg_match_all($pattern, $content, $matches);
                foreach ($matches[1] as $key) {
                    $references[] = [$labelFile, $key];
                }
            }
            preg_match_all('#LLL:EXT:ns_t3al/Resources/Private/Language/(locallang\w*\.xlf):([\w.\-]+)#', $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $references[] = [$match[1], $match[2]];
            }
            foreach ($references as [$labelFile, $key]) {
                if (!array_key_exists($key, $this->units(self::LANGUAGE_DIR . $labelFile))) {
                    $missing[] = $labelFile . ':' . $key . ' (' . substr($path, strlen(self::EXTENSION_PATH)) . ')';
                }
            }
        }

        self::assertSame([], array_values(array_unique($missing)));
    }

    /**
     * @return list<string> sprintf conversions, sorted (positional ones may move within a sentence)
     */
    private function placeholders(string $text): array
    {
        preg_match_all('/%(?:\d+\$)?[-+ 0#]*\d*(?:\.\d+)?[bcdeEfFgGosuxX%]/', $text, $matches);
        $placeholders = $matches[0];
        sort($placeholders);
        return $placeholders;
    }

    /**
     * @return array<string, string> trans-unit id => target text (source text for English files)
     */
    private function units(string $file): array
    {
        static $cache = [];
        if (!isset($cache[$file])) {
            $document = new \DOMDocument();
            self::assertTrue($document->load($file), basename($file) . ' is not well-formed');
            $isTranslation = (bool)preg_match('/^[a-z]{2}\./', basename($file));
            $cache[$file] = [];
            foreach ($document->getElementsByTagName('trans-unit') as $unit) {
                $text = $unit->getElementsByTagName($isTranslation ? 'target' : 'source')->item(0)?->textContent;
                $cache[$file][$unit->getAttribute('id')] = (string)$text;
            }
        }
        return $cache[$file];
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(): array
    {
        $files = glob(self::EXTENSION_PATH . 'ext_*.php') ?: [];
        foreach (['Classes', 'Configuration', 'Resources/Private/Templates', 'Resources/Private/Partials', 'Resources/Private/Layouts'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::EXTENSION_PATH . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (in_array($file->getExtension(), ['php', 'html'], true)) {
                    $files[] = $file->getPathname();
                }
            }
        }
        return $files;
    }
}

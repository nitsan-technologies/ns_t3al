<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Utility;

use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class XliffUtility
{
    public const LANGUAGE_DIR = 'Resources/Private/Language';

    /**
     * Parse errors are reported only by the exception, not additionally as PHP warnings.
     *
     * @throws \Exception When the XML is not well-formed
     */
    private static function loadXml(string $xml): \SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        try {
            return new \SimpleXMLElement($xml);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public static function simpleXMLElementToArray(\SimpleXMLElement $xml)
    {
        $attributes = $xml->attributes();
        $children = $xml->children();
        $text = trim((string)$xml);

        if (count($children) === 0 && count($attributes) === 0) {
            return $text;
        }

        $arr = [];
        foreach ($attributes as $k => $v) {
            $arr['@' . $k] = (string)$v;
        }

        foreach ($children as $child) {
            $childName = $child->getName();

            if (count($xml->$childName) > 1) {
                $arr[$childName][] = self::simpleXMLElementToArray($child);
            } else {
                $arr[$childName] = self::simpleXMLElementToArray($child);
            }
        }

        if ($text !== '') {
            $arr['_text'] = $text;
        }

        return $arr;
    }

    /**
     * Editable source files: only names fetchFilePath() accepts, so subfolder files are not listed.
     *
     * @return array<string, string> file name => file name, sorted
     */
    public static function getFileList(string $packagePath): array
    {
        $files = [];
        foreach (static::getLanguageFileIndex($packagePath . static::LANGUAGE_DIR) as $fileName => $languages) {
            if (($languages[0] ?? '') === 'default') {
                $files[$fileName] = $fileName;
            }
        }
        return $files;
    }

    /**
     * Existing XLF files of one language directory, read with a single directory listing.
     *
     * @return array<string, list<string>> base file name => language keys ('default' for the source file)
     */
    public static function getLanguageFileIndex(string $languageDirectory): array
    {
        $entries = is_dir($languageDirectory) ? (scandir($languageDirectory) ?: []) : [];
        $index = [];
        foreach ($entries as $entry) {
            if (!str_ends_with($entry, '.xlf')) {
                continue;
            }
            $parts = explode('.', $entry);
            if (count($parts) === 2 && static::isValidXliffFileName($entry)) {
                $index[$entry] ??= [];
                array_unshift($index[$entry], 'default');
            } elseif (count($parts) === 3 && static::isValidLanguageCode($parts[0]) && static::isValidXliffFileName($parts[1] . '.xlf')) {
                $index[$parts[1] . '.xlf'][] = $parts[0];
            }
        }
        ksort($index);
        return $index;
    }

    public function computeDiff($updatedData, $extensionKey, $fileName, $targetLanguage, $packagePath = ''): string
    {
        try {
            $sourcePath = $this->fetchFilePath($extensionKey, $fileName, $targetLanguage, $packagePath);
        } catch (\InvalidArgumentException $exception) {
            return '';
        }
        $existingData = is_file($sourcePath) ? (string)file_get_contents($sourcePath) : '';

        if (!is_string($updatedData) || $updatedData === '') {
            return '';
        }

        // No target file yet (first save of this language, e.g. a framework language pack or a new
        // de.* file): there is nothing to compare against, so every saved label is logged as added.
        // Comparing against the English source XLF instead logged every source label as deleted.
        $isNewFile = trim($existingData) === '';

        $targetDoc = new \DOMDocument();
        if (!@$targetDoc->loadXML($updatedData)) {
            return '';
        }
        $targetUnits = $this->unitTexts($targetDoc);

        $sourceUnits = [];
        if (!$isNewFile) {
            $sourceTextDoc = new \DOMDocument();
            if (!@$sourceTextDoc->loadXML($existingData)) {
                $isNewFile = true;
            } else {
                $sourceUnits = $this->unitTexts($sourceTextDoc);
            }
        }

        // Plain text: LogContentNormalizer + Fluid escape it when the log is displayed.
        $differences = [];
        foreach ($targetUnits as $id => $targetContent) {
            if (!isset($sourceUnits[$id])) {
                $added = [
                    'source' => ['ins' => $targetContent['source']],
                    'target' => ['ins' => $targetContent['target']],
                ];
                if ($isNewFile) {
                    // New file: only the texts that were actually saved (an empty target is no translation).
                    $added = array_filter($added, static fn (array $part): bool => $part['ins'] !== '');
                }
                if ($added !== []) {
                    $differences[$id] = $added;
                }
                continue;
            }
            $diffEntry = [];
            foreach (['source', 'target'] as $part) {
                if ($sourceUnits[$id][$part] !== $targetContent[$part]) {
                    $diffEntry[$part] = ['del' => $sourceUnits[$id][$part], 'ins' => $targetContent[$part]];
                }
            }
            if ($diffEntry !== []) {
                $differences[$id] = $diffEntry;
            }
        }

        foreach ($sourceUnits as $id => $sourceContent) {
            if (!isset($targetUnits[$id])) {
                $differences[$id] = [
                    'source' => ['del' => $sourceContent['source']],
                    'target' => ['del' => $sourceContent['target']],
                ];
            }
        }

        if ($differences) {
            return json_encode($differences, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT);
        }
        return '';
    }

    /**
     * Last duplicate wins — the unit TYPO3 and the grid read — so duplicates never show up as changes.
     *
     * @return array<string|int, array{source: string, target: string}>
     */
    private function unitTexts(\DOMDocument $document): array
    {
        $xpath = new \DOMXPath($document);
        $units = [];
        foreach ($xpath->query('//*[local-name()="trans-unit"]') as $unit) {
            if ($unit instanceof \DOMElement) {
                $units[$unit->getAttribute('id')] = [
                    'source' => $xpath->evaluate('string(./*[local-name()="source"])', $unit),
                    'target' => $xpath->evaluate('string(./*[local-name()="target"])', $unit),
                ];
            }
        }
        return $units;
    }

    public function stringToArray($fileData): array
    {
        $xmlData = self::loadXml((string)$fileData);
        $rawData = self::simpleXMLElementToArray($xmlData);

        $fileData = [];

        if (isset($rawData['file']['body']) && $rawData['file']['body'] !== '') {

            foreach ($rawData['file']['body']['trans-unit'] as $key => $unit) {

                if ((int)$key >= 0 && !is_string($key)) {

                    $source = $unit['source'] ?? null;
                    $target = $unit['target'] ?? null;

                    if (is_array($target)) {
                        $target = $target['_text'] ?? '';
                    }
                    if (isset($unit['@id'])) {
                        $fileData[$unit['@id']] = [
                            'source' => $source,
                            'target' => $target,
                        ];
                    }

                } else {

                    // Handle single element
                    $tag = $rawData['file']['body']['trans-unit'];
                    $fileData[$tag['@id']] = [
                        'source' => $tag['source'],
                        'target' => is_array($tag['target'] ?? null) ? ($tag['target']['_text'] ?? '') : ($tag['target'] ?? null),
                    ];

                }

            }
        }

        return $fileData;

    }

    public function writeFile(string $extensionKey, string $content, string $fileName, string $targetLanguage, string $packagePath = ''): bool
    {
        try {
            $file = $this->fetchFilePath($extensionKey, $fileName, $targetLanguage, $packagePath);
        } catch (\InvalidArgumentException $exception) {
            return false;
        }

        try {
            // mkdir_deep() returns void and throws when the directory cannot be created.
            GeneralUtility::mkdir_deep(dirname($file));
        } catch (\RuntimeException $exception) {
            return false;
        }

        if (GeneralUtility::writeFile($file, $content) !== false) {
            return true;
        }
        return false;

    }

    public function readFile(string $extensionKey, string $fileName, string $targetLanguage, string $packagePath = ''): string
    {
        try {
            $filePath = $this->fetchFilePath($extensionKey, $fileName, $targetLanguage, $packagePath);
        } catch (\InvalidArgumentException $exception) {
            return '';
        }

        if (!file_exists($filePath)) {
            return '';
        }
        return file_get_contents($filePath);

    }

    public function getFilePath(string $extensionKey, string $fileName, string $targetLanguage, string $packagePath = ''): string
    {
        try {
            return $this->fetchFilePath($extensionKey, $fileName, $targetLanguage, $packagePath);
        } catch (\InvalidArgumentException $exception) {
            return '';
        }
    }

    /**
     * Allowlist: basename only, .xlf suffix, no path separators or traversal.
     */
    public static function isValidXliffFileName(string $fileName): bool
    {
        if ($fileName === '' || str_contains($fileName, '..') || str_contains($fileName, '/') || str_contains($fileName, '\\')) {
            return false;
        }
        return (bool)preg_match('/^[a-zA-Z0-9_-]+\.xlf$/D', $fileName);
    }

    /**
     * Allowlist: safe charset for language codes (includes "default").
     */
    public static function isValidLanguageCode(string $languageCode): bool
    {
        if ($languageCode === '' || str_contains($languageCode, '..') || str_contains($languageCode, '/') || str_contains($languageCode, '\\')) {
            return false;
        }
        return (bool)preg_match('/^[a-zA-Z0-9_-]+$/D', $languageCode);
    }

    /**
     * @throws \InvalidArgumentException
     */
    protected function fetchFilePath(string $extensionKey, string $fileName, string $targetLanguage, string $packagePath = ''): string
    {
        if (!static::isValidXliffFileName($fileName)) {
            throw new \InvalidArgumentException('Invalid XLF file name.', 1740000001);
        }
        if (!static::isValidLanguageCode($targetLanguage)) {
            throw new \InvalidArgumentException('Invalid language code.', 1740000002);
        }

        if ($packagePath === '') {
            $packageManager = GeneralUtility::makeInstance(PackageManager::class);
            $package = $packageManager->getPackage($extensionKey);
            $packagePath = $package->getPackagePath();
        }

        $languageDirectory = rtrim($packagePath, '/') . '/' . static::LANGUAGE_DIR;
        if ($targetLanguage !== 'default') {
            $filePath = $languageDirectory . '/' . $targetLanguage . '.' . $fileName;
        } else {
            $filePath = $languageDirectory . '/' . $fileName;
        }

        $this->assertPathContainedInLanguageDirectory($filePath, $languageDirectory);

        return $filePath;
    }

    /**
     * Ensure the resolved path stays under the extension language directory.
     *
     * @throws \InvalidArgumentException
     */
    protected function assertPathContainedInLanguageDirectory(string $filePath, string $languageDirectory): void
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $languageDirectory), '/');
        $normalizedFile = str_replace('\\', '/', $filePath);
        $expectedPrefix = $normalizedRoot . '/';
        if (!str_starts_with($normalizedFile, $expectedPrefix) || str_contains(substr($normalizedFile, strlen($expectedPrefix)), '/')) {
            throw new \InvalidArgumentException('Path traversal detected.', 1740000003);
        }

        if (!is_dir($languageDirectory)) {
            // Directory may not exist yet (first write). Allowlist + prefix check above is sufficient.
            return;
        }

        $rootReal = realpath($languageDirectory);
        if ($rootReal === false) {
            throw new \InvalidArgumentException('Language directory is not accessible.', 1740000004);
        }

        $parent = dirname($filePath);
        if (is_dir($parent)) {
            $parentReal = realpath($parent);
            if ($parentReal === false || $parentReal !== $rootReal) {
                throw new \InvalidArgumentException('Path traversal detected.', 1740000005);
            }
        }

        if (file_exists($filePath)) {
            $fileReal = realpath($filePath);
            if ($fileReal === false || dirname($fileReal) !== $rootReal) {
                throw new \InvalidArgumentException('Path traversal detected.', 1740000006);
            }
        }
    }

    /**
     * @param array<string|int, array{source?: string, target?: string}> $dataValues
     * @param string $fileName English source file name, written to the "original" attribute
     */
    public function generateXml(
        string $extensionKey,
        string $targetLanguage,
        array $dataValues,
        bool $includeTarget = true,
        string $fileName = ''
    ): bool|string {
        return (new XliffFileGenerator())->generate($extensionKey, $targetLanguage, $dataValues, $includeTarget, $fileName);
    }

}

<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Service;

use NITSAN\NsT3AL\Backend\View\EditorViewDataProvider;
use NITSAN\NsT3AL\Utility\XliffUtility;

/**
 * Cross-extension label search (Search tab).
 *
 * Reads only files that exist (one directory listing per language directory); framework packages
 * only in the active language packs, like the editor. Values are compared after XLIFF parsing,
 * so escaped characters, attributes and multi-line values match what the editor shows.
 */
final class LabelSearchService
{
    public const MAX_RESULTS = 100;

    public function __construct(
        private readonly EditorViewDataProvider $editorViewDataProvider,
        private readonly ActiveLanguagesProvider $activeLanguagesProvider
    ) {
    }

    /**
     * @param string $languageFilter '' = all languages, 'default' = English source only, otherwise one language key
     * @return array{results: list<array{0: string, 1: string, 2: array<string, string>}>, limited: bool}
     */
    public function search(string $word, string $languageFilter = ''): array
    {
        $needle = $this->normalize($word);
        $results = [];
        if ($needle === '') {
            return ['results' => $results, 'limited' => false];
        }
        $probe = $this->literalProbe($needle);

        foreach ($this->editorViewDataProvider->getAvailableExtensions() as $extensionKey => $extension) {
            $extensionKey = (string)$extensionKey;
            $packagePath = (string)($extension['packagePath'] ?? '');
            foreach ($this->languageFiles($extensionKey, $packagePath, $languageFilter) as $file => $langKeys) {
                $matched = [];
                foreach ($langKeys as $langKey) {
                    if ($this->fileContains($extensionKey, $file, $langKey, $needle, $probe)) {
                        $matched[$langKey] = $langKey;
                    }
                }
                if ($matched === []) {
                    continue;
                }
                if (count($results) === self::MAX_RESULTS) {
                    return ['results' => $results, 'limited' => true];
                }
                $results[] = [$extensionKey, $file, $matched];
            }
        }

        return ['results' => $results, 'limited' => false];
    }

    /**
     * @return array<string, list<string>> base file name => language keys to search
     */
    private function languageFiles(string $extensionKey, string $packagePath, string $languageFilter): array
    {
        if ($packagePath === '') {
            return [];
        }
        $index = XliffUtility::getLanguageFileIndex(rtrim($packagePath, '/') . '/' . XliffUtility::LANGUAGE_DIR);
        // The module can only open files that have an English source file.
        $index = array_filter($index, static fn (array $langKeys): bool => in_array('default', $langKeys, true));

        if ($this->editorViewDataProvider->isSourceReadOnly($extensionKey)) {
            $index = array_map(static fn (): array => ['default'], $index);
            foreach ($this->activeLanguagesProvider->getActiveLanguages() as $langKey) {
                if (!XliffUtility::isValidLanguageCode($langKey)) {
                    continue;
                }
                $labelsDirectory = $this->editorViewDataProvider->resolveWriteBasePath($extensionKey, $langKey) . XliffUtility::LANGUAGE_DIR;
                foreach (XliffUtility::getLanguageFileIndex($labelsDirectory) as $file => $langKeys) {
                    if (isset($index[$file]) && in_array($langKey, $langKeys, true)) {
                        $index[$file][] = $langKey;
                    }
                }
            }
        }

        if ($languageFilter === '') {
            return $index;
        }
        $filtered = [];
        foreach ($index as $file => $langKeys) {
            if (in_array($languageFilter, $langKeys, true)) {
                $filtered[$file] = [$languageFilter];
            }
        }
        return $filtered;
    }

    private function fileContains(string $extensionKey, string $file, string $langKey, string $needle, string $probe): bool
    {
        $raw = $this->editorViewDataProvider->readRaw($extensionKey, $file, $langKey);
        if ($raw === '' || ($probe !== '' && !str_contains($raw, '&#') && mb_stripos($raw, $probe) === false)) {
            return false;
        }
        $field = $langKey === 'default' ? 'source' : 'target';
        foreach ($this->editorViewDataProvider->parseUnits($raw) as $unit) {
            $text = $this->normalize($this->editorViewDataProvider->unitText($unit[$field] ?? ''));
            if ($text !== '' && mb_stripos($text, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private function normalize(string $text): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Longest letter/digit run of the search term. Letters and digits appear literally in the raw XML
     * unless written as numeric character references, so a file without that run cannot match.
     */
    private function literalProbe(string $needle): string
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $needle, $matches);
        $probe = '';
        foreach ($matches[0] as $run) {
            if (mb_strlen($run) > mb_strlen($probe)) {
                $probe = $run;
            }
        }
        return $probe;
    }
}

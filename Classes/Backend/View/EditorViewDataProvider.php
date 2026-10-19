<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Backend\View;

use NITSAN\NsT3AL\Service\ActiveLanguagesProvider;
use NITSAN\NsT3AL\Service\ExtensionAccessService;
use NITSAN\NsT3AL\Utility\XliffDocumentUpdater;
use NITSAN\NsT3AL\Utility\XliffUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Localization\Locales;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extensionmanager\Utility\ListUtility;

/**
 * Loads and writes multi-language XLF grids (Translate-style $labels[key][lang]).
 */
class EditorViewDataProvider
{
    private const MODULE_NAME = 'tools_NsT3al';
    private const EDITOR_LOCK_CACHE = 'ns_t3al_editor_lock';
    private const EDITOR_LOCK_TTL = 600;
    public const KEY_PATTERN = '/^[A-Za-z0-9_.\-]+$/D';

    protected Locales $locales;
    protected XliffUtility $xlfUtility;
    protected ListUtility $listUtility;
    protected ExtensionAccessService $extensionAccessService;
    protected XliffDocumentUpdater $xliffDocumentUpdater;
    protected ActiveLanguagesProvider $activeLanguagesProvider;

    public function __construct(
        Locales $locales,
        XliffUtility $xlfUtility,
        ListUtility $listUtility,
        ExtensionAccessService $extensionAccessService,
        XliffDocumentUpdater $xliffDocumentUpdater,
        ActiveLanguagesProvider $activeLanguagesProvider
    ) {
        $this->locales = $locales;
        $this->xlfUtility = $xlfUtility;
        $this->listUtility = $listUtility;
        $this->extensionAccessService = $extensionAccessService;
        $this->xliffDocumentUpdater = $xliffDocumentUpdater;
        $this->activeLanguagesProvider = $activeLanguagesProvider;
    }

    /**
     * Extensions the current backend user may edit (all for admins, granted ones for others).
     *
     * @return array<string, mixed>
     */
    public function getAvailableExtensions(string $extType = 'All'): array
    {
        $extType = $this->normalizeExtType($extType);
        $available = $this->listUtility->getAvailableExtensions('');
        $filtered = [];
        foreach ($available as $extkey => $extension) {
            if (!is_array($extension)) {
                continue;
            }
            if (empty($extension['key'])) {
                $extension['key'] = (string)$extkey;
            }
            if (empty($extension['title'])) {
                $extension['title'] = (string)$extkey;
            }
            if ($extType !== 'All') {
                $type = (string)($extension['type'] ?? '');
                if ($type !== $extType) {
                    continue;
                }
            }
            $filtered[$extkey] = $extension;
        }
        return $this->extensionAccessService->filterEditable($filtered);
    }

    public function isExistingLanguageFile(string $extensionKey, string $file): bool
    {
        try {
            return isset($this->getFileList($extensionKey)[$file]);
        } catch (\Throwable $exception) {
            return false;
        }
    }

    public function normalizeExtType(string $extType): string
    {
        $extType = trim($extType);
        if (in_array($extType, ['All', 'System', 'Local'], true)) {
            return $extType;
        }
        return 'All';
    }

    public function getPackageType(string $extensionKey): string
    {
        return $this->listUtility->getExtension($extensionKey)->getPackageMetaData()->getPackageType();
    }

    /**
     * @return array<string, string>
     */
    public function getFileList(string $extensionKey): array
    {
        $packagePath = $this->listUtility->getExtension($extensionKey)->getPackagePath();
        return $this->xlfUtility->getFileList($packagePath);
    }

    /**
     * @return array<string, string>
     */
    public function getLanguageOptions(string $extensionKey = ''): array
    {
        $languages = $this->locales->getLanguages();
        // English is the source language ("default"); an "en." translation file is never used by TYPO3.
        unset($languages['en']);
        if ($extensionKey === '') {
            return $languages;
        }

        try {
            $packageType = $this->getPackageType($extensionKey);
        } catch (\Throwable $exception) {
            return $languages;
        }

        if ($packageType !== 'typo3-cms-framework') {
            return $languages;
        }

        $activeLanguages = $this->activeLanguagesProvider->getActiveLanguages();
        $withoutDefault = $languages;
        unset($withoutDefault['default']);
        $filtered = array_intersect_key($withoutDefault, array_flip($activeLanguages));
        if (isset($languages['default'])) {
            $filtered = ['default' => $languages['default']] + $filtered;
        }
        return $filtered;
    }

    /**
     * @param list<string> $langKeys
     * @return array<string, array<string, string>>
     */
    public function loadLabels(string $extensionKey, string $file, array $langKeys): array
    {
        $labels = [];
        $defaultUnits = $this->readUnits($extensionKey, $file, 'default');
        foreach ($defaultUnits as $key => $unit) {
            $labels[$key]['default'] = $this->unitText($unit['source'] ?? '');
        }

        foreach ($langKeys as $langKey) {
            if ($langKey === '' || $langKey === 'default') {
                continue;
            }
            $units = $this->readUnits($extensionKey, $file, $langKey);
            foreach ($labels as $key => $row) {
                $labels[$key][$langKey] = $this->unitText($units[$key]['target'] ?? '');
            }
            foreach ($units as $key => $unit) {
                if (!isset($labels[$key])) {
                    $labels[$key]['default'] = $this->unitText($unit['source'] ?? '');
                    $labels[$key][$langKey] = $this->unitText($unit['target'] ?? '');
                }
            }
        }

        foreach ($labels as $key => $row) {
            $ordered = ['default' => (string)($row['default'] ?? '')];
            foreach ($langKeys as $langKey) {
                if ($langKey === '' || $langKey === 'default') {
                    continue;
                }
                $ordered[$langKey] = (string)($row[$langKey] ?? '');
            }
            $labels[$key] = $ordered;
        }

        return $labels;
    }

    /**
     * Builds the XLF content for one language from the grid without regenerating existing files:
     * an existing file is updated in place; a missing target file is created with translated units only.
     *
     * @param array<string|int, array<string, string>> $labels
     * @param array<string, string> $renames Old key => new key
     * @param bool $keepGridOrder Write the units in grid order (see isGridOrderChanged())
     * @return array{content: string, changed: bool}|null Null when the existing file cannot be parsed
     */
    public function buildLanguageFile(
        string $extensionKey,
        string $file,
        string $langKey,
        array $labels,
        array $renames = [],
        bool $sortByKey = false,
        bool $keepGridOrder = false
    ): ?array {
        $isTargetFile = $langKey !== 'default';
        $units = [];
        foreach ($labels as $key => $row) {
            if ((string)$key === '') {
                continue;
            }
            $units[$key] = [
                'source' => (string)($row['default'] ?? ''),
                'target' => $isTargetFile ? (string)($row[$langKey] ?? '') : '',
            ];
        }

        $existing = $this->readRaw($extensionKey, $file, $langKey);
        if ($existing !== '') {
            return $this->xliffDocumentUpdater->update($existing, $units, $renames, $isTargetFile, $sortByKey, $keepGridOrder);
        }

        if ($isTargetFile) {
            $units = array_filter($units, static fn (array $unit): bool => trim($unit['target']) !== '');
            if ($units === []) {
                return ['content' => '', 'changed' => false];
            }
        }
        if ($sortByKey) {
            ksort($units);
        }
        $xml = $this->xlfUtility->generateXml($extensionKey, $isTargetFile ? $langKey : 'en', $units, $isTargetFile, $file);
        return is_string($xml) && $xml !== '' ? ['content' => $xml, 'changed' => true] : null;
    }

    /**
     * @param array<string|int, string> $keys Posted keys[oldKey] = newKey
     * @return array<string, string>
     */
    public function buildKeyRenames(array $keys): array
    {
        $renames = [];
        foreach ($keys as $oldKey => $newKey) {
            $newKey = trim((string)$newKey);
            if ($newKey !== '' && (string)$oldKey !== $newKey) {
                $renames[(string)$oldKey] = $newKey;
            }
        }
        return $renames;
    }

    /**
     * Whether the user moved rows (or inserted new rows between existing ones): the grid order differs
     * from what a save without reordering produces — English file order with renamed keys in place,
     * removed keys dropped and new keys appended. Only then are the files rewritten in grid order, so a
     * plain save never reorders files whose order differs from the English file.
     *
     * @param list<string|int> $existingKeys Keys in English file order
     * @param list<string|int> $gridKeys Final keys in grid order (after key changes)
     * @param array<string, string> $renames Old key => new key
     */
    public function isGridOrderChanged(array $existingKeys, array $gridKeys, array $renames): bool
    {
        $gridKeys = array_values(array_unique(array_map('strval', $gridKeys)));
        $inGrid = array_fill_keys($gridKeys, true);
        $expected = [];
        foreach ($existingKeys as $key) {
            $key = $renames[(string)$key] ?? (string)$key;
            if (isset($inGrid[$key]) && !isset($expected[$key])) {
                $expected[$key] = $key;
            }
        }
        foreach ($gridKeys as $key) {
            $expected[$key] ??= $key;
        }
        return array_values($expected) !== $gridKeys;
    }

    /**
     * Whether the requested grid adds, removes or renames keys or changes English source texts.
     *
     * @param array<string|int, array<string, string>> $existingLabels
     * @param array<string|int, array<string, string>> $requestedLabels
     */
    public function hasSourceChanges(array $existingLabels, array $requestedLabels): bool
    {
        if (array_diff_key($existingLabels, $requestedLabels) !== [] || array_diff_key($requestedLabels, $existingLabels) !== []) {
            return true;
        }
        foreach ($requestedLabels as $key => $row) {
            if (trim((string)($row['default'] ?? '')) !== trim((string)($existingLabels[$key]['default'] ?? ''))) {
                return true;
            }
        }
        return false;
    }

    public function isSourceReadOnly(string $extensionKey): bool
    {
        try {
            return $this->getPackageType($extensionKey) === 'typo3-cms-framework';
        } catch (\Throwable $exception) {
            return false;
        }
    }

    public function createEmptyFile(string $extensionKey, string $newFile): bool
    {
        if (!XliffUtility::isValidXliffFileName($newFile)) {
            return false;
        }

        $destination = $this->xlfUtility->getFilePath($extensionKey, $newFile, 'default', '');
        if ($destination === '') {
            return false;
        }

        if (is_file($destination)) {
            return false;
        }
        try {
            GeneralUtility::mkdir_deep(dirname($destination));
        } catch (\RuntimeException $exception) {
            return false;
        }
        // No placeholder unit: the grid offers a blank row for a file without labels.
        $xml = $this->xlfUtility->generateXml($extensionKey, 'en', [], false, $newFile);
        return is_string($xml) && GeneralUtility::writeFile($destination, $xml);
    }

    /**
     * @return array<string, mixed>
     */
    public function getModuleData(): array
    {
        // Entries of the former format (a serialized string in "data") are ignored.
        $data = $this->getBackendUser()?->getModuleData(self::MODULE_NAME);
        return is_array($data) && isset($data['extkey']) ? $data : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function setModuleData(array $data): void
    {
        $this->getBackendUser()?->pushModuleData(self::MODULE_NAME, $data);
    }

    protected function getBackendUser(): ?BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        return $backendUser instanceof BackendUserAuthentication ? $backendUser : null;
    }

    /**
     * Shared concurrent-edit lock (cross-user via caching framework).
     * Returns the foreign lock payload when another session holds it within the TTL; otherwise null.
     *
     * @return array{sessid: string, time: int, username?: string, extensionKey?: string, file?: string}|null
     */
    public function checkAndAcquireEditorLock(string $extensionKey, string $file, string $sessid, string $username = ''): ?array
    {
        if ($extensionKey === '') {
            return null;
        }

        $cache = $this->getEditorLockCache();
        $cacheId = $this->buildEditorLockCacheId($extensionKey, $file);
        $now = time();
        $existing = $cache->get($cacheId);
        $conflict = null;

        if (is_array($existing)
            && ($existing['sessid'] ?? '') !== ''
            && ($existing['sessid'] ?? '') !== $sessid
            && ($now - (int)($existing['time'] ?? 0)) < self::EDITOR_LOCK_TTL
        ) {
            $conflict = [
                'sessid' => (string)$existing['sessid'],
                'time' => (int)$existing['time'],
                'username' => (string)($existing['username'] ?? ''),
                'extensionKey' => (string)($existing['extensionKey'] ?? $extensionKey),
                'file' => (string)($existing['file'] ?? $file),
            ];
        }

        $cache->set(
            $cacheId,
            [
                'sessid' => $sessid,
                'time' => $now,
                'username' => $username,
                'extensionKey' => $extensionKey,
                'file' => $file,
            ],
            [],
            self::EDITOR_LOCK_TTL
        );

        return $conflict;
    }

    protected function getEditorLockCache(): FrontendInterface
    {
        return GeneralUtility::makeInstance(CacheManager::class)->getCache(self::EDITOR_LOCK_CACHE);
    }

    protected function buildEditorLockCacheId(string $extensionKey, string $file): string
    {
        return 'lock_' . hash('sha256', $extensionKey . "\0" . $file);
    }

    /**
     * Overlays imported CSV rows on the current labels: imported cells replace existing ones, keys missing
     * in the CSV are kept unchanged, new keys are appended. "updated" counts only existing keys whose text
     * changes, so re-importing an unchanged export reports 0.
     *
     * @param array<string|int, array<string, string>> $existingLabels
     * @param array<string|int, array<string, string>> $importedLabels
     * @param list<string> $langKeys Languages of the resulting grid
     * @return array{labels: array<string, array<string, string>>, updated: int, added: int}
     */
    public function mergeImportedLabels(array $existingLabels, array $importedLabels, array $langKeys): array
    {
        $merged = $existingLabels;
        $updated = 0;
        $added = 0;
        foreach ($importedLabels as $key => $importedRow) {
            if (isset($merged[$key])) {
                foreach ($importedRow as $langKey => $value) {
                    if (in_array($langKey, $langKeys, true) && (string)$value !== (string)($merged[$key][$langKey] ?? '')) {
                        $updated++;
                        break;
                    }
                }
                $merged[$key] = array_merge($merged[$key], $importedRow);
            } else {
                $added++;
                $merged[$key] = $importedRow;
            }
        }
        foreach ($merged as $key => $row) {
            $ordered = [];
            foreach ($langKeys as $langKey) {
                $ordered[$langKey] = (string)($row[$langKey] ?? '');
            }
            $merged[$key] = $ordered;
        }
        return ['labels' => $merged, 'updated' => $updated, 'added' => $added];
    }

    /**
     * Keys a save would produce more than once, and keys not yet in the file that do not match KEY_PATTERN
     * (existing keys are never rejected, whatever their format).
     *
     * @param array<string|int, array<string, string>> $labels
     * @param array<string|int, string> $keys Posted keys[rowKey] = key
     * @param list<string|int> $existingKeys Keys currently in the English file
     * @return array{duplicates: list<string>, invalid: list<string>}
     */
    public function findKeyProblems(array $labels, array $keys, array $existingKeys): array
    {
        $existing = array_fill_keys(array_map('strval', $existingKeys), true);
        $occurrences = [];
        $invalid = [];
        foreach (array_keys($labels) as $rowKey) {
            $rowKey = (string)$rowKey;
            $newKey = isset($keys[$rowKey]) ? trim((string)$keys[$rowKey]) : $rowKey;
            if ($newKey === '') {
                continue;
            }
            $occurrences[$newKey] = ($occurrences[$newKey] ?? 0) + 1;
            if (!isset($existing[$newKey]) && preg_match(self::KEY_PATTERN, $newKey) !== 1) {
                $invalid[$newKey] = $newKey;
            }
        }
        return [
            'duplicates' => array_map('strval', array_keys(array_filter($occurrences, static fn (int $count): bool => $count > 1))),
            'invalid' => array_values($invalid),
        ];
    }

    /**
     * @param array<string, array<string, string>> $labels
     * @param array<string, string> $keys
     * @return array<string, array<string, string>>
     */
    public function applyKeyChanges(array $labels, array $keys): array
    {
        $result = [];
        foreach ($labels as $oldKey => $row) {
            $newKey = isset($keys[$oldKey]) ? trim((string)$keys[$oldKey]) : (string)$oldKey;
            if ($newKey === '') {
                continue;
            }
            if (isset($result[$newKey])) {
                $result[$newKey] = array_merge($result[$newKey], $row);
            } else {
                $result[$newKey] = $row;
            }
        }
        return $result;
    }

    public function unitText($value): string
    {
        if (is_array($value)) {
            return (string)($value['_text'] ?? $value['source'] ?? '');
        }
        return (string)$value;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function readUnits(string $extensionKey, string $file, string $langKey): array
    {
        return $this->parseUnits($this->readRaw($extensionKey, $file, $langKey));
    }

    /**
     * @return array<string, array<string, mixed>> Empty for empty or unparsable XLF
     */
    public function parseUnits(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        try {
            $parsed = $this->xlfUtility->stringToArray($raw);
            return is_array($parsed) ? $parsed : [];
        } catch (\Throwable $exception) {
            return [];
        }
    }

    public function readRaw(string $extensionKey, string $file, string $langKey): string
    {
        $basePath = $this->resolveWriteBasePath($extensionKey, $langKey);
        try {
            $content = $this->xlfUtility->readFile($extensionKey, $file, $langKey, $basePath);
        } catch (\Throwable $exception) {
            return '';
        }
        return is_string($content) ? $content : '';
    }

    /**
     * Base path of a translation file: '' = inside the extension; framework packages use the labels path.
     */
    public function resolveWriteBasePath(string $extensionKey, string $langKey): string
    {
        if ($langKey === 'default') {
            return '';
        }
        try {
            if ($this->getPackageType($extensionKey) !== 'typo3-cms-framework') {
                return '';
            }
        } catch (\Throwable $exception) {
            return '';
        }

        return Environment::getLabelsPath() . '/' . $langKey . '/' . $extensionKey . '/';
    }
}

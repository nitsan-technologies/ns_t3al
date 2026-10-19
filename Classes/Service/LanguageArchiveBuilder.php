<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Service;

use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Packs a language directory (sub folders included) into a new ZIP archive below var/transient.
 */
final class LanguageArchiveBuilder
{
    /**
     * @return string Absolute path of the archive; the caller must delete it
     * @throws \RuntimeException When there is nothing to pack or the archive cannot be written
     */
    public function build(string $languageDirectory): string
    {
        $languageDirectory = rtrim($languageDirectory, '/') . '/';
        $entries = $this->collectEntries($languageDirectory);
        if (array_filter($entries, static fn (string $entry): bool => !str_ends_with($entry, '/')) === []) {
            throw new \RuntimeException('No language files to export in ' . $languageDirectory, 1791300001);
        }

        $archivePath = GeneralUtility::tempnam('ns_t3al_export_', '.zip');
        $zip = new \ZipArchive();
        $openResult = $zip->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($openResult !== true) {
            @unlink($archivePath);
            throw new \RuntimeException('ZIP archive could not be created (error ' . (int)$openResult . ')', 1791300002);
        }

        foreach ($entries as $entry) {
            if (str_ends_with($entry, '/')) {
                $zip->addEmptyDir(rtrim($entry, '/'));
            } else {
                $zip->addFile($languageDirectory . $entry, $entry);
            }
        }

        if (!$zip->close()) {
            @unlink($archivePath);
            throw new \RuntimeException('ZIP archive could not be written', 1791300003);
        }
        return $archivePath;
    }

    /**
     * @return list<string> Paths relative to $languageDirectory, directories end with "/"
     */
    private function collectEntries(string $languageDirectory): array
    {
        if (!is_dir($languageDirectory)) {
            return [];
        }
        // getAllFilesAndFoldersInPath() appends sub folder names directly, so the path needs its trailing slash.
        $absolutePaths = GeneralUtility::getAllFilesAndFoldersInPath(
            [],
            $languageDirectory,
            '',
            true,
            99,
            (string)($GLOBALS['TYPO3_CONF_VARS']['EXT']['excludeForPackaging'] ?? '')
        );
        $relativePaths = GeneralUtility::removePrefixPathFromList($absolutePaths, $languageDirectory);
        if (!is_array($relativePaths)) {
            return [];
        }
        $entries = array_values(array_filter(
            array_map(static fn ($path): string => ltrim((string)$path, '/'), $relativePaths),
            static fn (string $path): bool => $path !== ''
        ));
        sort($entries);
        return $entries;
    }
}

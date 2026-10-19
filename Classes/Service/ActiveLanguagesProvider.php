<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Service;

use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Active (installed) language packs across TYPO3 12/13/14.
 *
 * LanguagePackService lives in EXT:core on TYPO3 14 but in EXT:install on 12/13,
 * and EXT:install is optional in Composer installs, so it must not be a hard dependency.
 */
final class ActiveLanguagesProvider
{
    /**
     * @return list<string> ISO codes, e.g. ['de', 'fr']
     */
    public function getActiveLanguages(): array
    {
        if (class_exists(\TYPO3\CMS\Core\Localization\LanguagePackService::class)) {
            return array_values(GeneralUtility::makeInstance(\TYPO3\CMS\Core\Localization\LanguagePackService::class)->getActiveLanguages());
        }
        if (class_exists(\TYPO3\CMS\Install\Service\LanguagePackService::class)) {
            return array_values(GeneralUtility::makeInstance(\TYPO3\CMS\Install\Service\LanguagePackService::class)->getActiveLanguages());
        }

        return array_values(array_filter(
            (array)($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['lang']['availableLanguages'] ?? [])
        ));
    }
}

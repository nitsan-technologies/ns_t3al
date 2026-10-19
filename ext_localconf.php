<?php

declare(strict_types=1);

defined('TYPO3') or die();

// Shared concurrent-edit locks for the Translate editor (cross-user, ~10 min TTL).
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['ns_t3al_editor_lock'] ??= [
    'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
    'backend' => \TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend::class,
    'options' => [
        'defaultLifetime' => 600,
    ],
    'groups' => ['system'],
];

// DeepL target languages (GET /v3/languages), refreshed daily.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][\NITSAN\NsT3AL\Service\DeepLLanguageProvider::CACHE_IDENTIFIER] ??= [
    'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
    'backend' => \TYPO3\CMS\Core\Cache\Backend\FileBackend::class,
    'options' => [
        'defaultLifetime' => 86400,
    ],
    'groups' => ['system'],
];

// Per-extension edit permission for non-admins (Backend user group → Access Rights → Custom module options).
$GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions'][\NITSAN\NsT3AL\Service\ExtensionAccessService::PERMISSION_GROUP]
    = \NITSAN\NsT3AL\Service\ExtensionAccessService::buildPermissionOptions(
        \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Package\PackageManager::class)
    );

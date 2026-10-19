<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Package\PackageManager;

/**
 * Per-extension edit permission for non-admin backend users.
 *
 * Admins may edit every extension. Non-admins may only edit extensions explicitly
 * granted to one of their groups via "Access Rights → Custom module options".
 */
class ExtensionAccessService
{
    public const PERMISSION_GROUP = 'ns_t3al_extensions';

    private const LANGUAGE_FILE = 'LLL:EXT:ns_t3al/Resources/Private/Language/locallang_mod.xlf:';

    public function canEdit(string $extensionKey): bool
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser === null || $extensionKey === '') {
            return false;
        }
        if ($backendUser->isAdmin()) {
            return true;
        }
        return $backendUser->check('custom_options', self::PERMISSION_GROUP . ':' . $extensionKey);
    }

    /**
     * @template T
     * @param array<string, T> $extensions Keyed by extension key
     * @return array<string, T>
     */
    public function filterEditable(array $extensions): array
    {
        return array_filter(
            $extensions,
            fn ($extensionKey): bool => $this->canEdit((string)$extensionKey),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Permission options for $GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions'].
     * Framework packages are omitted: non-admins may never write them.
     *
     * @return array{header: string, items: array<string, array{0: string, 1: string, 2: string}>}
     */
    public static function buildPermissionOptions(PackageManager $packageManager): array
    {
        $items = [];
        foreach ($packageManager->getActivePackages() as $package) {
            $metaData = $package->getPackageMetaData();
            if ($metaData->isFrameworkType()) {
                continue;
            }
            $extensionKey = $package->getPackageKey();
            $title = (string)$metaData->getTitle();
            $items[$extensionKey] = [
                $title !== '' && $title !== $extensionKey ? $title . ' (' . $extensionKey . ')' : $extensionKey,
                'status-status-permission-granted',
                self::LANGUAGE_FILE . 'permission.extensions.item.description',
            ];
        }
        ksort($items);

        return [
            'header' => self::LANGUAGE_FILE . 'permission.extensions.header',
            'items' => $items,
        ];
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        return $backendUser instanceof BackendUserAuthentication ? $backendUser : null;
    }
}

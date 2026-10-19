<?php

declare(strict_types=1);

use NITSAN\NsT3AL\Imaging\InlineSvgIconProvider;
use TYPO3\CMS\Core\Information\Typo3Version;

$isV14OrHigher = (new Typo3Version())->getMajorVersion() >= 14;

return [
    // Module icon: v14 = themed outline (currentColor), v12/v13 = full blue tile
    't3al-icon' => [
        'provider' => InlineSvgIconProvider::class,
        'source' => $isV14OrHigher
            ? 'EXT:ns_t3al/Resources/Public/Icons/ExtensionV14.svg'
            : 'EXT:ns_t3al/Resources/Public/Icons/Extension.svg',
    ],
];

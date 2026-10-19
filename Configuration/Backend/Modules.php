<?php

declare(strict_types=1);

use NITSAN\NsT3AL\Controller\ModuleController;

return [
    'tools_NsT3al' => [
        'parent' => 'tools',
        'position' => 'bottom',
        'access' => 'user',
        'workspaces' => '*',
        'path' => '/module/tools/ns-t3al',
        'iconIdentifier' => 't3al-icon',
        'labels' => 'LLL:EXT:ns_t3al/Resources/Private/Language/locallang_mod.xlf',
        'extensionName' => 'NsT3AL',
        'controllerActions' => [
            ModuleController::class => [
                'list',
                'translate',
                'save',
                'search',
                'createFile',
                'importCsv',
                'exportCsv',
                'logs',
                'export',
                'guide',
            ],
        ],
    ],
];

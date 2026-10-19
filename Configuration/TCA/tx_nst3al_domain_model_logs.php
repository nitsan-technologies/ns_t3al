<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:ns_t3al/Resources/Private/Language/locallang_db.xlf:tx_nst3al_domain_model_logs',
        'label' => 'extension_name',
        'label_alt' => 'output_file',
        'label_alt_force' => true,
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'default_sortby' => 'crdate DESC',
        'hideTable' => true,
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'typeicon_classes' => [
            'default' => 't3al-icon',
        ],
    ],
    'types' => [
        '1' => ['showitem' => 'extension_name, source_file, output_file, target_language, request_mode, status, crdate, content,
             --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access, hidden'],
    ],
    'columns' => [
        'hidden' => [
            'exclude' => true,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.visible',
            'config' => [
                'type' => 'check',
                'default' => 0,
            ],
        ],
        'crdate' => [
            'label' => 'LLL:EXT:ns_t3al/Resources/Private/Language/locallang_db.xlf:tx_nst3al_domain_model_logs.crdate',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
            ],
        ],
        'status' => [
            'exclude' => true,
            'label' => 'LLL:EXT:ns_t3al/Resources/Private/Language/locallang_db.xlf:tx_nst3al_domain_model_logs.status',
            'config' => [
                'type' => 'check',
                'readOnly' => true,
                'default' => 0,
            ],
        ],
        'extension_name' => [
            'exclude' => true,
            'label' => 'LLL:EXT:ns_t3al/Resources/Private/Language/locallang_db.xlf:tx_nst3al_domain_model_logs.extension_name',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'eval' => 'trim',
                'readOnly' => true,
                'default' => '',
                // TYPO3 v14+: replaces ctrl.searchFields (ignored on v12/v13)
                'searchable' => true,
            ],
        ],
        'source_file' => [
            'exclude' => true,
            'label' => 'LLL:EXT:ns_t3al/Resources/Private/Language/locallang_db.xlf:tx_nst3al_domain_model_logs.source_file',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'eval' => 'trim',
                'readOnly' => true,
                'default' => '',
            ],
        ],
        'output_file' => [
            'exclude' => true,
            'label' => 'LLL:EXT:ns_t3al/Resources/Private/Language/locallang_db.xlf:tx_nst3al_domain_model_logs.output_file',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'eval' => 'trim',
                'readOnly' => true,
                'default' => '',
            ],
        ],
        'content' => [
            'exclude' => true,
            'label' => 'LLL:EXT:ns_t3al/Resources/Private/Language/locallang_db.xlf:tx_nst3al_domain_model_logs.content',
            'config' => [
                'type' => 'text',
                'cols' => 60,
                'rows' => 5,
                'readOnly' => true,
            ],
        ],
        'request_mode' => [
            'exclude' => true,
            'label' => 'LLL:EXT:ns_t3al/Resources/Private/Language/locallang_db.xlf:tx_nst3al_domain_model_logs.request_mode',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'target_language' => [
            'exclude' => true,
            'label' => 'LLL:EXT:ns_t3al/Resources/Private/Language/locallang_db.xlf:tx_nst3al_domain_model_logs.target_language',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
    ],
];

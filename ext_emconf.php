<?php

$EM_CONF['ns_t3al'] = [
    'title' => 'AI-Powered XLIFF Translation',
    'description' => 'Translate TYPO3 extension XLIFF files with AI and publish them to Crowdin from the TYPO3 backend.',
    'category' => 'be',
    'author' => 'Team T3Planet',
    'author_email' => 'support@t3planet.de',
    'author_company' => 'T3Planet // NITSAN',
    'state' => 'stable',
    'clearCacheOnLoad' => 0,
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'php' => '8.2.0-8.5.99',
            'typo3' => '12.4.18-14.9.99',
            'backend' => '12.4.18-14.9.99',
            'extbase' => '12.4.18-14.9.99',
            'extensionmanager' => '12.4.18-14.9.99',
            'fluid' => '12.4.18-14.9.99',
            'ns_license' => '14.5.4-14.9.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];

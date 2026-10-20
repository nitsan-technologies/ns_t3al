<?php

$EM_CONF['ns_t3al'] = [
    'title' => 'AI-Powered XLIFF Translation',
    'description' => 'AI translation for TYPO3 extension language files. Translate XLIFF / locallang.xlf labels with AI right in the TYPO3 backend, with no more copying labels into external tools. Auto-translate missing labels with DeepL, OpenAI ChatGPT, Google Gemini or Google Translate, review and edit them in a side-by-side editor grid, and save only what changed. Built for the whole TYPO3 team: integrators and developers localize extensions, editors fix wording, and admins control access per extension. Includes label search, CSV import/export, ZIP export, translation logs with diff, and Crowdin upload for TYPO3 core translations. Multilingual TYPO3 made simple. Free and open source, no licence key.',
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

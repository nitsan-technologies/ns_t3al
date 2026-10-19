<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Tests\Unit\Support;

use NITSAN\NsT3AL\Backend\View\EditorViewDataProvider;
use NITSAN\NsT3AL\Service\ActiveLanguagesProvider;
use NITSAN\NsT3AL\Service\ExtensionAccessService;
use NITSAN\NsT3AL\Utility\XliffDocumentUpdater;
use NITSAN\NsT3AL\Utility\XliffUtility;
use TYPO3\CMS\Core\Localization\Locales;
use TYPO3\CMS\Extensionmanager\Utility\ListUtility;

/**
 * Provider with real XLIFF collaborators; readRaw() returns $existingFiles[langKey] instead of reading disk.
 */
trait EditorViewDataProviderFactoryTrait
{
    /**
     * @param array<string, string> $existingFiles langKey => raw XLF content
     */
    private function createEditorViewDataProvider(array $existingFiles = []): EditorViewDataProvider
    {
        $provider = new class (
            $this->createStub(Locales::class),
            new XliffUtility(),
            $this->createStub(ListUtility::class),
            $this->createStub(ExtensionAccessService::class),
            new XliffDocumentUpdater(),
            new ActiveLanguagesProvider()
        ) extends EditorViewDataProvider {
            /** @var array<string, string> */
            public array $existingFiles = [];

            public function readRaw(string $extensionKey, string $file, string $langKey): string
            {
                return $this->existingFiles[$langKey] ?? '';
            }
        };
        $provider->existingFiles = $existingFiles;
        return $provider;
    }
}

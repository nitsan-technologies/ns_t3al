<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Tests\Unit\Backend\View;

use NITSAN\NsT3AL\Tests\Unit\Support\EditorViewDataProviderFactoryTrait;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class EditorViewDataProviderModuleDataTest extends UnitTestCase
{
    use EditorViewDataProviderFactoryTrait;

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function moduleDataIsStoredAsArray(): void
    {
        $data = ['extkey' => 'news', 'file' => 'locallang.xlf', 'langKeys' => ['default', 'de']];
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->expects(self::once())->method('pushModuleData')->with('tools_NsT3al', $data);
        $GLOBALS['BE_USER'] = $backendUser;

        $this->createEditorViewDataProvider()->setModuleData($data);
    }

    #[Test]
    public function storedModuleDataIsReturned(): void
    {
        $data = ['extkey' => 'news', 'file' => 'locallang.xlf'];
        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->method('getModuleData')->willReturn($data);
        $GLOBALS['BE_USER'] = $backendUser;

        self::assertSame($data, $this->createEditorViewDataProvider()->getModuleData());
    }

    #[Test]
    public function formerSerializedFormatIsIgnored(): void
    {
        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->method('getModuleData')->willReturn([
            'data' => serialize(['extkey' => 'news']),
            'function' => null,
        ]);
        $GLOBALS['BE_USER'] = $backendUser;

        self::assertSame([], $this->createEditorViewDataProvider()->getModuleData());
    }

    #[Test]
    public function withoutBackendUserNothingIsStoredOrReturned(): void
    {
        unset($GLOBALS['BE_USER']);
        $provider = $this->createEditorViewDataProvider();

        $provider->setModuleData(['extkey' => 'news']);
        self::assertSame([], $provider->getModuleData());
    }
}

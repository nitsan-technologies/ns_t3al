<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Tests\Unit\Backend\View;

use NITSAN\NsT3AL\Backend\View\LogsViewDataProvider;
use NITSAN\NsT3AL\Domain\Repository\LogsRepository;
use NITSAN\NsT3AL\Tests\Unit\Support\TemporaryDirectoryTrait;
use NITSAN\NsT3AL\Utility\XliffUtility;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Localization\Locales;
use TYPO3\CMS\Core\Package\MetaData;
use TYPO3\CMS\Core\Package\PackageInterface;
use TYPO3\CMS\Extensionmanager\Utility\ListUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class LogsViewDataProviderTest extends UnitTestCase
{
    use TemporaryDirectoryTrait;

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
        parent::tearDown();
    }

    #[Test]
    public function logsAreFilteredByTheFirstFileOfThePackageFromTheInjectedListUtility(): void
    {
        $packagePath = $this->createTemporaryDirectory() . '/';
        $this->writeFile($packagePath . 'Resources/Private/Language/locallang.xlf', '<xliff/>');
        $package = $this->createStub(PackageInterface::class);
        $package->method('getPackagePath')->willReturn($packagePath);
        $package->method('getPackageMetaData')->willReturn(new MetaData('demo_ext'));
        $listUtility = $this->createMock(ListUtility::class);
        $listUtility->expects(self::once())->method('getExtension')->with('demo_ext')->willReturn($package);
        $logsRepository = $this->createMock(LogsRepository::class);
        $logsRepository->expects(self::once())
            ->method('findRecords')
            ->with(['sourceFile' => 'locallang.xlf', 'extensionKey' => 'demo_ext', 'targetLanguage' => ''])
            ->willThrowException(new \RuntimeException('logs requested', 1791460000));

        $this->expectExceptionCode(1791460000);
        $this->createSubject($listUtility, $logsRepository)->buildLogsData('demo_ext');
    }

    #[Test]
    public function emptyExtensionKeyReturnsDefaultsWithoutResolvingExtension(): void
    {
        $listUtility = $this->createMock(ListUtility::class);
        $listUtility->expects(self::never())->method('getExtension');

        $assign = $this->createSubject($listUtility)->buildLogsData('', '', '', '/return');

        self::assertSame('/return', $assign['returnUrl']);
        self::assertSame([], $assign['logRecords']);
        self::assertSame([], $assign['pagination']);
    }

    private function createSubject(ListUtility $listUtility, ?LogsRepository $logsRepository = null): LogsViewDataProvider
    {
        return new LogsViewDataProvider(
            $logsRepository ?? $this->createStub(LogsRepository::class),
            new XliffUtility(),
            $this->createStub(Locales::class),
            $listUtility
        );
    }
}

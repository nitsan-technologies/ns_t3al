<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Tests\Unit\Service;

use NITSAN\NsT3AL\Service\CrowdinService;
use NITSAN\NsT3AL\Tests\Unit\Support\RecordingRequestFactoryTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class CrowdinServiceTest extends UnitTestCase
{
    use RecordingRequestFactoryTrait;

    private const API_URL = 'https://api.crowdin.test/api/v2/';

    protected bool $resetSingletonInstances = true;

    /**
     * @param \Closure(string $uri, string $method): ResponseInterface $respond
     * @param array<string, string> $extConf
     */
    private function createService(\Closure $respond, array $extConf = []): CrowdinService
    {
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($extConf + [
            'accessToken' => 'token',
            'crowdinApiUrl' => self::API_URL,
        ]);
        return new class ($this->createRequestFactory($respond), $extensionConfiguration) extends CrowdinService {
            protected function getLocallangTranslation(string $id, array $arguments = []): string
            {
                return $id . ($arguments === [] ? '' : '|' . implode('|', $arguments));
            }
        };
    }

    /**
     * Crowdin list response with the given "data" items.
     *
     * @param array<int, array<string, mixed>> $items
     */
    private static function listResponse(array $items): JsonResponse
    {
        return new JsonResponse(['data' => array_map(static fn (array $item): array => ['data' => $item], $items)]);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function createListService(array $items, array $extConf = []): CrowdinService
    {
        return $this->createService(static fn (): JsonResponse => self::listResponse($items), $extConf);
    }

    #[Test]
    public function extensionDirectoryLookupMatchesTheExactPathAndPaginates(): void
    {
        $directoryId = $this->createListService([
            ['id' => 10, 'name' => 'core_extra', 'path' => '/main/typo3/sysext/core_extra'],
            ['id' => 11, 'name' => 'core', 'path' => '/main/typo3/sysext/core'],
        ])->fetchDirectory(5, 'core');

        self::assertSame(11, $directoryId);
        self::assertSame(self::API_URL . 'projects/5/directories?filter=core&limit=500&offset=0', $this->requests[0]['uri']);
    }

    #[Test]
    public function directoryOutsideTheSystemExtensionPathIsIgnored(): void
    {
        self::assertSame(0, $this->createListService([
            ['id' => 11, 'name' => 'core', 'path' => '/other/core'],
        ])->fetchDirectory(5, 'core'));
    }

    #[Test]
    public function listsAreReadBeyondTheFirstPage(): void
    {
        $firstPage = [];
        for ($i = 1; $i <= CrowdinService::PAGE_LIMIT; $i++) {
            $firstPage[] = ['id' => $i, 'name' => 'core' . $i, 'path' => '/main/typo3/sysext/core' . $i];
        }
        $service = $this->createService(static fn (string $uri): JsonResponse => str_contains($uri, 'offset=0')
            ? self::listResponse($firstPage)
            : self::listResponse([['id' => 999, 'name' => 'core', 'path' => '/main/typo3/sysext/core']]));

        self::assertSame(999, $service->fetchDirectory(5, 'core'));
        self::assertCount(2, $this->requests);
        self::assertStringEndsWith('limit=500&offset=500', $this->requests[1]['uri']);
    }

    #[Test]
    public function systemExtensionPathIsConfigurableAndNormalized(): void
    {
        $directoryId = $this->createListService([
            ['id' => 11, 'name' => 'core', 'path' => '/main/typo3/sysext/core'],
            ['id' => 12, 'name' => 'core', 'path' => '/12.4/typo3/sysext/core'],
        ], ['crowdinSystemExtensionPath' => ' 12.4/typo3/sysext/ '])->fetchDirectory(5, 'core');

        self::assertSame(12, $directoryId);
    }

    #[Test]
    public function fileLookupMatchesTheExactPathNotASubstring(): void
    {
        $fileId = $this->createListService([
            ['id' => 30, 'type' => 'xliff', 'name' => 'my_locallang.xlf', 'path' => '/main/typo3/sysext/core/Resources/Private/Language/my_locallang.xlf'],
            ['id' => 31, 'type' => 'xliff', 'name' => 'locallang.xlf', 'path' => '/main/typo3/sysext/core/Resources/Private/Language/Sub/locallang.xlf'],
            ['id' => 32, 'type' => 'xliff', 'name' => 'locallang.xlf', 'path' => '/main/typo3/sysext/core/Resources/Private/Language/locallang.xlf'],
        ])->fetchFile(5, 11, 'locallang.xlf', 'core');

        self::assertSame(32, $fileId);
        self::assertSame(self::API_URL . 'projects/5/files?directoryId=11&recursion=1&limit=500&offset=0', $this->requests[0]['uri']);
    }

    #[Test]
    public function fileInALanguageSubFolderIsFound(): void
    {
        $fileId = $this->createListService([
            ['id' => 32, 'type' => 'xliff', 'name' => 'locallang.xlf', 'path' => '/main/typo3/sysext/core/Resources/Private/Language/locallang.xlf'],
            ['id' => 33, 'type' => 'xliff', 'name' => 'locallang.xlf', 'path' => '/main/typo3/sysext/core/Resources/Private/Language/Sub/locallang.xlf'],
        ])->fetchFile(5, 11, 'Sub/locallang.xlf', 'core');

        self::assertSame(33, $fileId);
    }

    #[Test]
    public function ambiguousFileWithoutExactPathIsNotGuessed(): void
    {
        self::assertSame(0, $this->createListService([
            ['id' => 40, 'type' => 'xliff', 'path' => '/a/Resources/Private/Language/locallang.xlf'],
            ['id' => 41, 'type' => 'xliff', 'path' => '/b/Resources/Private/Language/locallang.xlf'],
        ])->fetchFile(5, 11, 'locallang.xlf'));
    }

    #[Test]
    public function fileNameWithParentSegmentIsRejectedWithoutRequest(): void
    {
        self::assertSame(0, $this->createListService([])->fetchFile(5, 11, '../locallang.xlf', 'core'));
        self::assertSame([], $this->requests);
    }

    #[Test]
    public function pathSegmentsAreEncoded(): void
    {
        $this->createListService([])->fetchFile('5/../6', 22, 'locallang.xlf');

        self::assertSame(self::API_URL . 'projects/5%2F..%2F6/files?directoryId=22&recursion=1&limit=500&offset=0', $this->requests[0]['uri']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function apiUrlProvider(): array
    {
        return [
            'with slash' => ['https://api.crowdin.com/api/v2/', 'https://api.crowdin.com/api/v2/'],
            'without slash' => ['https://acme.api.crowdin.com/api/v2', 'https://acme.api.crowdin.com/api/v2/'],
            'several slashes and spaces' => [' https://api.crowdin.com/api/v2// ', 'https://api.crowdin.com/api/v2/'],
            'empty' => ['', CrowdinService::DEFAULT_API_URL],
        ];
    }

    #[Test]
    #[DataProvider('apiUrlProvider')]
    public function apiUrlIsNormalized(string $configured, string $expected): void
    {
        self::assertSame($expected, CrowdinService::normalizeApiUrl($configured));
    }

    #[Test]
    public function requestsUseTheNormalizedApiUrl(): void
    {
        $this->createListService([], ['crowdinApiUrl' => 'https://api.crowdin.test/api/v2'])->fetchProject(5);

        self::assertSame(self::API_URL . 'projects/5', $this->requests[0]['uri']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function languageIdProvider(): array
    {
        return [
            'plain' => ['de', 'de'],
            'pt_BR' => ['pt_BR', 'pt-BR'],
            'zh_CN' => ['zh_CN', 'zh-CN'],
            'es_ES' => ['es_ES', 'es-ES'],
            'fr_CA' => ['fr_CA', 'fr-CA'],
            'es (Crowdin region id)' => ['es', 'es-ES'],
            'pt (Crowdin region id)' => ['pt', 'pt-PT'],
            'sv (Crowdin region id)' => ['sv', 'sv-SE'],
            'TYPO3 "ch" (Chinese simplified)' => ['ch', 'zh-CN'],
        ];
    }

    #[Test]
    #[DataProvider('languageIdProvider')]
    public function typo3LanguageKeysAreMappedToCrowdinIds(string $languageKey, string $expected): void
    {
        self::assertSame($expected, CrowdinService::toCrowdinLanguageId($languageKey));
    }

    #[Test]
    public function languageIdIsResolvedAgainstTheProjectTargets(): void
    {
        self::assertSame('de', CrowdinService::resolveLanguageId('de', ['de', 'pt-BR']));
        self::assertSame('pt-BR', CrowdinService::resolveLanguageId('pt_BR', ['de', 'pt-BR']));
        self::assertSame('fr-FR', CrowdinService::resolveLanguageId('fr', ['fr-FR']));
        self::assertSame('es-ES', CrowdinService::resolveLanguageId('es', ['es-ES', 'es-MX']));
        self::assertSame('es', CrowdinService::resolveLanguageId('es', ['es']));
        self::assertNull(CrowdinService::resolveLanguageId('en', ['en-GB', 'en-US']));
        self::assertNull(CrowdinService::resolveLanguageId('pt_BR', ['pt-PT']));
        self::assertSame('pt-BR', CrowdinService::resolveLanguageId('pt_BR', []));
    }

    /**
     * Fake Crowdin project: target languages, core directory, core locallang.xlf.
     *
     * @param array<string, int> $statusByEndpoint e.g. ['projects/5' => 401]
     */
    private function createProjectService(array $statusByEndpoint = []): CrowdinService
    {
        return $this->createService(static function (string $uri, string $method) use ($statusByEndpoint): ResponseInterface {
            $path = (string)preg_replace('/\?.*$/', '', substr($uri, strlen(self::API_URL)));
            if (isset($statusByEndpoint[$path])) {
                return new JsonResponse(['error' => ['code' => $statusByEndpoint[$path]]], $statusByEndpoint[$path]);
            }
            return match (true) {
                $path === 'projects/5' => new JsonResponse(['data' => ['id' => 5, 'targetLanguageIds' => ['de', 'pt-BR']]]),
                $path === 'user' => new JsonResponse(['data' => ['id' => 1]]),
                $path === 'projects/5/directories' => self::listResponse([['id' => 11, 'path' => '/main/typo3/sysext/core']]),
                $path === 'projects/5/files' => self::listResponse([['id' => 32, 'type' => 'xliff', 'path' => '/main/typo3/sysext/core/Resources/Private/Language/locallang.xlf']]),
                $path === 'storages' => new JsonResponse(['data' => ['id' => 77]], 201),
                str_starts_with($path, 'projects/5/translations/') => new JsonResponse(['data' => []]),
                default => new JsonResponse([], 404),
            };
        });
    }

    #[Test]
    public function uploadUsesTheProjectLanguageIdAndTheExactFile(): void
    {
        $result = $this->createProjectService()->uploadTranslation(5, 'core', 'locallang.xlf', 'pt_BR', '<xliff/>');

        self::assertTrue($result['status']);
        $last = end($this->requests);
        self::assertSame(self::API_URL . 'projects/5/translations/pt-BR', $last['uri']);
        self::assertSame(['storageId' => 77, 'autoApproveImported' => true, 'fileId' => 32], json_decode($last['options']['body'], true));
    }

    #[Test]
    public function unauthorizedTokenIsReportedAsAuthenticationError(): void
    {
        $result = $this->createProjectService(['projects/5' => 401])->uploadTranslation(5, 'core', 'locallang.xlf', 'de', '<xliff/>');

        self::assertFalse($result['status']);
        self::assertSame('translation.write.crowdin.error.auth|401', $result['message']);
    }

    #[Test]
    public function forbiddenFileListIsReportedAsAuthenticationError(): void
    {
        $result = $this->createProjectService(['projects/5/files' => 403])->uploadTranslation(5, 'core', 'locallang.xlf', 'de', '<xliff/>');

        self::assertSame('translation.write.crowdin.error.auth|403', $result['message']);
    }

    #[Test]
    public function unknownProjectIsReportedAsProjectNotFound(): void
    {
        $result = $this->createProjectService(['projects/5' => 404])->uploadTranslation(5, 'core', 'locallang.xlf', 'de', '<xliff/>');

        self::assertSame('translation.write.crowdin.error.project_not_found|5', $result['message']);
    }

    #[Test]
    public function invalidTokenBehindAProject404IsReportedAsAuthenticationError(): void
    {
        $result = $this->createProjectService(['projects/5' => 404, 'user' => 401])->uploadTranslation(5, 'core', 'locallang.xlf', 'de', '<xliff/>');

        self::assertSame('translation.write.crowdin.error.auth|401', $result['message']);
        self::assertSame(self::API_URL . 'user', $this->requests[1]['uri']);
    }

    #[Test]
    public function missingFileIsReportedWithItsPath(): void
    {
        $result = $this->createProjectService()->uploadTranslation(5, 'core', 'locallang_other.xlf', 'de', '<xliff/>');

        self::assertSame(
            'translation.write.crowdin.error.file_not_found|/main/typo3/sysext/core/Resources/Private/Language/locallang_other.xlf',
            $result['message']
        );
    }

    #[Test]
    public function languageThatIsNoProjectTargetIsReported(): void
    {
        $result = $this->createProjectService()->uploadTranslation(5, 'core', 'locallang.xlf', 'fr', '<xliff/>');

        self::assertSame('translation.write.crowdin.error.language_not_in_project|fr|fr', $result['message']);
        self::assertCount(1, $this->requests);
    }
}

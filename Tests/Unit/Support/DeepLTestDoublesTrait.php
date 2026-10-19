<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Tests\Unit\Support;

use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * HTTP and cache doubles for the DeepL services; every request is recorded in $requests.
 * The languages cache is an in-memory get/set stub shared by every cache manager of one test,
 * so it behaves the same on TYPO3 12–14 (core cache backend constructors differ per major).
 */
trait DeepLTestDoublesTrait
{
    use RecordingRequestFactoryTrait;

    /** @var array<string, mixed> */
    private array $languagesCacheEntries = [];

    private function createCacheManager(): CacheManager
    {
        $cache = $this->createStub(FrontendInterface::class);
        $cache->method('get')->willReturnCallback(
            fn (string $entryIdentifier): mixed => $this->languagesCacheEntries[$entryIdentifier] ?? false
        );
        $cache->method('set')->willReturnCallback(
            function (string $entryIdentifier, mixed $data): void {
                $this->languagesCacheEntries[$entryIdentifier] = $data;
            }
        );
        $cacheManager = $this->createStub(CacheManager::class);
        $cacheManager->method('getCache')->willReturn($cache);
        return $cacheManager;
    }

    /**
     * GET /v3/languages?resource=translate_text response body.
     *
     * @param array<string, bool> $languages BCP 47 code => usable_as_target
     */
    private static function languagesResponse(array $languages): JsonResponse
    {
        $body = [];
        foreach ($languages as $lang => $usableAsTarget) {
            $body[] = [
                'lang' => $lang,
                'name' => $lang,
                'usable_as_source' => true,
                'usable_as_target' => $usableAsTarget,
                'status' => 'stable',
                'features' => [],
            ];
        }
        return new JsonResponse($body);
    }
}

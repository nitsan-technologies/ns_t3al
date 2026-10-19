<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Service;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * DeepL text translation target languages from GET /v3/languages, cached for a day. Falls back to
 * DeepLLanguageMapper::FALLBACK_TARGET_LANGUAGES when the list cannot be fetched, so translating never
 * depends on the languages endpoint.
 */
final class DeepLLanguageProvider implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const CACHE_IDENTIFIER = 'ns_t3al_deepl_languages';

    private const CACHE_LIFETIME = 86400;

    private const HTTP_TIMEOUT = 10;

    private const HTTP_CONNECT_TIMEOUT = 5;

    /** @var array<string, list<string>> Languages URL => target codes, per request */
    private array $runtimeCache = [];

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly CacheManager $cacheManager
    ) {
    }

    /**
     * @param string $translateUrl Configured translate endpoint, e.g. https://api-free.deepl.com/v2/translate
     * @return list<string> Uppercase DeepL target codes
     */
    public function getTargetLanguages(string $translateUrl, string $apiKey): array
    {
        $languagesUrl = self::buildLanguagesUrl($translateUrl);
        if ($languagesUrl === null || $apiKey === '') {
            return DeepLLanguageMapper::FALLBACK_TARGET_LANGUAGES;
        }
        if (isset($this->runtimeCache[$languagesUrl])) {
            return $this->runtimeCache[$languagesUrl];
        }

        $cache = $this->getCache();
        $cacheId = 'targets_' . hash('sha256', $languagesUrl);
        $cached = $cache?->get($cacheId);
        if (is_array($cached) && $cached !== []) {
            return $this->runtimeCache[$languagesUrl] = $cached;
        }

        $languages = $this->fetchTargetLanguages($languagesUrl, $apiKey);
        if ($languages === null) {
            return $this->runtimeCache[$languagesUrl] = DeepLLanguageMapper::FALLBACK_TARGET_LANGUAGES;
        }
        $cache?->set($cacheId, $languages, [], self::CACHE_LIFETIME);
        return $this->runtimeCache[$languagesUrl] = $languages;
    }

    /**
     * Same host as the translate endpoint; null for endpoints that do not end in /v2/translate (e.g. proxies).
     */
    public static function buildLanguagesUrl(string $translateUrl): ?string
    {
        $url = preg_replace('#/v2/translate/?$#', '/v3/languages?resource=translate_text', trim($translateUrl), 1, $count);
        return $count === 1 ? $url : null;
    }

    /**
     * @return list<string>|null Null when the request fails or the response holds no target language
     */
    private function fetchTargetLanguages(string $languagesUrl, string $apiKey): ?array
    {
        try {
            $response = $this->requestFactory->request($languagesUrl, 'GET', [
                'timeout' => self::HTTP_TIMEOUT,
                'connect_timeout' => self::HTTP_CONNECT_TIMEOUT,
                'headers' => [
                    'Authorization' => 'DeepL-Auth-Key ' . $apiKey,
                    'Accept' => 'application/json',
                ],
            ]);
            $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            $this->logger?->warning('Fetching the DeepL target languages failed, using the built-in list', [
                'exception' => $exception::class . ': ' . $exception->getMessage(),
            ]);
            return null;
        }

        $languages = [];
        foreach (is_array($data) ? $data : [] as $language) {
            if (is_array($language) && ($language['usable_as_target'] ?? false) === true && is_string($language['lang'] ?? null)) {
                $languages[] = strtoupper($language['lang']);
            }
        }
        if ($languages === []) {
            $this->logger?->warning('The DeepL languages response contains no target language, using the built-in list');
            return null;
        }
        return $languages;
    }

    private function getCache(): ?FrontendInterface
    {
        try {
            return $this->cacheManager->getCache(self::CACHE_IDENTIFIER);
        } catch (\Throwable $exception) {
            return null;
        }
    }
}

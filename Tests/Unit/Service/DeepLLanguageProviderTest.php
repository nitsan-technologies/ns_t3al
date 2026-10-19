<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Tests\Unit\Service;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use NITSAN\NsT3AL\Service\DeepLLanguageMapper;
use NITSAN\NsT3AL\Service\DeepLLanguageProvider;
use NITSAN\NsT3AL\Tests\Unit\Support\DeepLTestDoublesTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class DeepLLanguageProviderTest extends UnitTestCase
{
    use DeepLTestDoublesTrait;

    protected bool $resetSingletonInstances = true;

    private const FREE_URL = 'https://api-free.deepl.com/v2/translate';

    private function createSubject(\Closure $respond): DeepLLanguageProvider
    {
        return new DeepLLanguageProvider($this->createRequestFactory($respond), $this->createCacheManager());
    }

    public static function translateUrls(): array
    {
        return [
            'free' => [self::FREE_URL, 'https://api-free.deepl.com/v3/languages?resource=translate_text'],
            'pro' => ['https://api.deepl.com/v2/translate', 'https://api.deepl.com/v3/languages?resource=translate_text'],
            'trailing slash' => ['https://api.deepl.com/v2/translate/ ', 'https://api.deepl.com/v3/languages?resource=translate_text'],
            'proxy' => ['https://proxy.example.com/deepl', null],
        ];
    }

    #[Test]
    #[DataProvider('translateUrls')]
    public function languagesUrlUsesTheHostOfTheTranslateEndpoint(string $translateUrl, ?string $expected): void
    {
        self::assertSame($expected, DeepLLanguageProvider::buildLanguagesUrl($translateUrl));
    }

    #[Test]
    public function targetLanguagesAreFetchedWithHeaderAuthentication(): void
    {
        $subject = $this->createSubject(static fn (): JsonResponse => self::languagesResponse(['de' => true, 'en' => false, 'en-US' => true, 'zh-Hant' => true]));

        self::assertSame(['DE', 'EN-US', 'ZH-HANT'], $subject->getTargetLanguages(self::FREE_URL, 'secret:fx'));
        self::assertCount(1, $this->requests);
        self::assertSame('GET', $this->requests[0]['method']);
        self::assertSame('https://api-free.deepl.com/v3/languages?resource=translate_text', $this->requests[0]['uri']);
        self::assertSame('DeepL-Auth-Key secret:fx', $this->requests[0]['options']['headers']['Authorization']);
        self::assertStringNotContainsString('secret', $this->requests[0]['uri']);
    }

    #[Test]
    public function targetLanguagesAreCachedAcrossRequests(): void
    {
        $respond = static fn (): JsonResponse => self::languagesResponse(['ja' => true]);
        $this->createSubject($respond)->getTargetLanguages(self::FREE_URL, 'key');
        $secondRequest = $this->createSubject($respond)->getTargetLanguages(self::FREE_URL, 'key');

        self::assertSame(['JA'], $secondRequest);
        self::assertCount(1, $this->requests);
    }

    public static function failures(): array
    {
        return [
            'network error' => [static fn (string $uri): Response => throw new ConnectException('timeout', new Request('GET', $uri))],
            'invalid json' => [static fn (): Response => new Response(200, [], '<html>Bad gateway</html>')],
            'no target language' => [static fn (): JsonResponse => self::languagesResponse(['en' => false])],
        ];
    }

    #[Test]
    #[DataProvider('failures')]
    public function failureFallsBackToTheBuiltInListWithoutCachingIt(\Closure $respond): void
    {
        $subject = $this->createSubject($respond);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');
        $subject->setLogger($logger);

        self::assertSame(DeepLLanguageMapper::FALLBACK_TARGET_LANGUAGES, $subject->getTargetLanguages(self::FREE_URL, 'key'));
        self::assertSame(DeepLLanguageMapper::FALLBACK_TARGET_LANGUAGES, $subject->getTargetLanguages(self::FREE_URL, 'key'));
        self::assertCount(1, $this->requests, 'one attempt per request');

        $this->createSubject(static fn (): JsonResponse => self::languagesResponse(['ko' => true]))->getTargetLanguages(self::FREE_URL, 'key');
        self::assertCount(2, $this->requests, 'the next request retries');
    }

    #[Test]
    public function proxyEndpointOrMissingKeyUsesTheBuiltInListWithoutRequest(): void
    {
        $subject = $this->createSubject(static fn (): JsonResponse => self::languagesResponse(['de' => true]));

        self::assertSame(DeepLLanguageMapper::FALLBACK_TARGET_LANGUAGES, $subject->getTargetLanguages('https://proxy.example.com/deepl', 'key'));
        self::assertSame(DeepLLanguageMapper::FALLBACK_TARGET_LANGUAGES, $subject->getTargetLanguages(self::FREE_URL, ''));
        self::assertSame([], $this->requests);
    }
}

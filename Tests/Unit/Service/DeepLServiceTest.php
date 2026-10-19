<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Tests\Unit\Service;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use NITSAN\NsT3AL\Service\DeepLLanguageProvider;
use NITSAN\NsT3AL\Service\DeepLService;
use NITSAN\NsT3AL\Tests\Unit\Support\DeepLTestDoublesTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class DeepLServiceTest extends UnitTestCase
{
    use DeepLTestDoublesTrait;

    protected bool $resetSingletonInstances = true;

    private const TRANSLATE_URL = 'https://api-free.deepl.com/v2/translate';

    /**
     * Fake DeepL: the languages endpoint lists $targets, the translate endpoint prefixes each text with the target code.
     *
     * @param list<string> $targets
     */
    private function createSubject(array $targets = ['de', 'en-GB', 'en-US', 'ja', 'pt-BR', 'pt-PT', 'zh-Hans', 'zh-Hant'], ?\Closure $translate = null): DeepLService
    {
        $translate ??= static fn (array $json): ResponseInterface => new JsonResponse(['translations' => array_map(
            static fn (string $text): array => ['detected_source_language' => 'EN', 'text' => $json['target_lang'] . ': ' . $text],
            $json['text']
        )]);
        $requestFactory = $this->createRequestFactory(
            static fn (string $uri, string $method, array $options): ResponseInterface => str_contains($uri, '/v3/languages')
                ? self::languagesResponse(array_fill_keys($targets, true))
                : $translate($options['json'], $uri)
        );
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['deeplApiKey' => 'secret:fx', 'deeplApiUrl' => self::TRANSLATE_URL]);

        return new class ($requestFactory, $extensionConfiguration, new DeepLLanguageProvider($requestFactory, $this->createCacheManager())) extends DeepLService {
            protected function translateProviderLabel(string $id): string
            {
                return $id === 'translation.deepL.unsupported_language.message' ? 'DeepL cannot translate into "%s".' : $id;
            }
        };
    }

    /**
     * @return array{uri: string, method: string, options: array}
     */
    private function translateRequest(): array
    {
        $translateRequests = array_values(array_filter($this->requests, static fn (array $request): bool => $request['uri'] === self::TRANSLATE_URL));
        self::assertCount(1, $translateRequests);
        return $translateRequests[0];
    }

    #[Test]
    public function translationIsRequestedWithHeaderAuthenticationAndJsonBody(): void
    {
        $result = $this->createSubject()->translateRequest('de', ['save' => 'Save', 'close' => 'Close']);
        $request = $this->translateRequest();

        self::assertSame(['status' => 'true', 'content' => ['save' => 'DE: Save', 'close' => 'DE: Close']], $result);
        self::assertSame('POST', $request['method']);
        self::assertSame('DeepL-Auth-Key secret:fx', $request['options']['headers']['Authorization']);
        self::assertSame(['text' => ['Save', 'Close'], 'target_lang' => 'DE'], $request['options']['json']);
        self::assertArrayNotHasKey('form_params', $request['options']);
        self::assertStringNotContainsString('auth_key', json_encode($request['options'], JSON_THROW_ON_ERROR));
    }

    public static function targetCodes(): array
    {
        return [
            'English' => ['en', 'EN-GB'],
            'Portuguese' => ['pt', 'PT-PT'],
            'Brazilian Portuguese' => ['pt_BR', 'PT-BR'],
            'Chinese (Traditional)' => ['zh', 'ZH-HANT'],
            'Chinese (Simple)' => ['ch', 'ZH-HANS'],
            'Japanese' => ['ja', 'JA'],
        ];
    }

    #[Test]
    #[DataProvider('targetCodes')]
    public function typo3LanguageIsSentAsTheCurrentDeepLTargetCode(string $typo3Key, string $targetLang): void
    {
        $this->createSubject()->translateRequest($typo3Key, ['save' => 'Save']);

        self::assertSame($targetLang, $this->translateRequest()['options']['json']['target_lang']);
    }

    #[Test]
    public function unsupportedLanguageIsReportedWithoutTranslateRequest(): void
    {
        $result = $this->createSubject()->translateRequest('kl', ['save' => 'Save']);

        self::assertSame(
            ['status' => 'false', 'message' => 'DeepL cannot translate into "kl".', 'title' => 'translation.deepL.error.unsupported_languages'],
            $result
        );
        self::assertSame([], array_filter($this->requests, static fn (array $request): bool => $request['uri'] === self::TRANSLATE_URL));
    }

    #[Test]
    public function translationCountMismatchIsAnError(): void
    {
        $subject = $this->createSubject(translate: static fn (): ResponseInterface => new JsonResponse(['translations' => [['text' => 'Nur eins']]]));

        self::assertSame(
            ['status' => 'false', 'message' => 'translation.generation.error'],
            $subject->translateRequest('de', ['save' => 'Save', 'close' => 'Close'])
        );
    }

    #[Test]
    public function rejectedRequestReturnsTheProviderFailure(): void
    {
        $subject = $this->createSubject(translate: static fn (array $json, string $uri): ResponseInterface => throw new ClientException(
            'Forbidden',
            new Request('POST', $uri),
            new Response(403)
        ));
        $result = $subject->translateRequest('de', ['save' => 'Save']);

        self::assertSame('false', $result['status']);
        self::assertSame('translation.provider.rejected', $result['message']);
        self::assertFalse($result['retryLater']);
    }

    #[Test]
    public function rateLimitedRequestAsksToRetryLater(): void
    {
        $subject = $this->createSubject(translate: static fn (array $json, string $uri): ResponseInterface => throw new ClientException(
            'Too Many Requests',
            new Request('POST', $uri),
            new Response(429)
        ));
        $result = $subject->translateRequest('de', ['save' => 'Save']);

        self::assertSame('false', $result['status']);
        self::assertTrue($result['retryLater']);
    }
}

<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Tests\Unit\Service;

use NITSAN\NsT3AL\Service\AiProviderResolver;
use NITSAN\NsT3AL\Service\DeepLService;
use NITSAN\NsT3AL\Service\GeminiService;
use NITSAN\NsT3AL\Service\GoogleService;
use NITSAN\NsT3AL\Service\OpenAiService;
use NITSAN\NsT3AL\Service\TranslationService;
use NITSAN\NsT3AL\Tests\Unit\Support\RecordingRequestFactoryTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Localization\Locales;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class GeminiServiceTest extends UnitTestCase
{
    use RecordingRequestFactoryTrait;

    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/';

    protected bool $resetSingletonInstances = true;

    /**
     * @param \Closure(string $uri, string $method, array $options): ResponseInterface $respond
     */
    private function createSubject(\Closure $respond, string $configuredModel = ''): GeminiService
    {
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['geminiApiKey' => 'key', 'geminiApiModel' => $configuredModel]);

        return new class ($this->createRequestFactory($respond), $extensionConfiguration) extends GeminiService {
            protected function translateProviderLabel(string $id): string
            {
                return $id . ' (HTTP %d)';
            }
        };
    }

    public static function modelCases(): \Generator
    {
        yield 'allowed request model' => ['gemini-3.6-flash', 'gemini-2.5-flash-lite', 'gemini-3.6-flash'];
        yield 'no request: configured model' => ['', 'gemini-2.5-flash-lite', 'gemini-2.5-flash-lite'];
        yield 'unknown request: configured model' => ['gemini-1.0-ultra', 'gemini-2.5-flash-lite', 'gemini-2.5-flash-lite'];
        yield 'path injection: configured model' => ['../../files', 'gemini-2.5-flash-lite', 'gemini-2.5-flash-lite'];
        yield 'unknown request and configured: default' => ['x', 'gemini-pro-custom', 'gemini-2.5-flash'];
        yield 'nothing set: default' => ['', '', 'gemini-2.5-flash'];
    }

    #[Test]
    #[DataProvider('modelCases')]
    public function onlyAllowlistedModelsAreRequested(string $requested, string $configured, string $expected): void
    {
        $service = $this->createSubject(static fn (): JsonResponse => new JsonResponse([
            'candidates' => [['content' => ['parts' => [['text' => '{"btn.save":"Speichern"}']]]]],
        ]), $configured);

        $result = $service->translateRequest('de', 'German', ['btn.save' => 'Save'], $requested);

        self::assertSame(self::ENDPOINT . $expected . ':generateContent', $this->requests[0]['uri'] ?? null);
        self::assertSame('true', $result['status']);
    }

    public static function outageCases(): \Generator
    {
        $error = static fn (int $code): array => ['error' => ['code' => $code, 'message' => 'The model is overloaded.', 'status' => 'UNAVAILABLE']];
        yield '503 JSON error' => [static fn (): ResponseInterface => new JsonResponse($error(503), 503), 'translation.provider.server_error (HTTP 503)'];
        yield '500 JSON error' => [static fn (): ResponseInterface => new JsonResponse($error(500), 500), 'translation.provider.server_error (HTTP 500)'];
        yield '502 HTML page from a proxy' => [static fn (): ResponseInterface => new HtmlResponse('<html>Bad Gateway</html>', 502), 'translation.provider.server_error (HTTP 502)'];
        yield '429 rate limit' => [static fn (): ResponseInterface => new JsonResponse($error(429), 429), 'translation.provider.rejected (HTTP 429)'];
    }

    #[Test]
    #[DataProvider('outageCases')]
    public function outageResponsesAskToRetryLater(\Closure $respond, string $message): void
    {
        $result = $this->createSubject($respond)->translateRequest('de', 'German', ['btn.save' => 'Save']);

        self::assertSame(['status' => 'false', 'message' => $message, 'retryLater' => true], $result);
    }

    #[Test]
    public function clientErrorKeepsGeminiMessageAndDoesNotStopTheBatches(): void
    {
        $result = $this->createSubject(static fn (): ResponseInterface => new JsonResponse(
            ['error' => ['code' => 400, 'message' => 'API key not valid.']],
            400
        ))->translateRequest('de', 'German', ['btn.save' => 'Save']);

        self::assertSame(['status' => 'false', 'message' => '400: API key not valid.'], $result);
    }

    #[Test]
    public function outageStopsTranslationAfterTheFirstBatchWithOneError(): void
    {
        $gemini = $this->createSubject(static fn (): ResponseInterface => new JsonResponse(['error' => ['code' => 503, 'message' => 'Unavailable']], 503));
        $locales = $this->createStub(Locales::class);
        $locales->method('getLanguages')->willReturn(['de' => 'German']);
        $translationService = new TranslationService(
            $this->createStub(OpenAiService::class),
            $this->createStub(DeepLService::class),
            $gemini,
            $this->createStub(GoogleService::class),
            $locales,
            new AiProviderResolver($this->createStub(ExtensionConfiguration::class))
        );
        $labels = [];
        for ($index = 1; $index <= 160; $index++) {
            $labels['label.' . $index] = ['source' => 'Label ' . $index, 'target' => ''];
        }

        $result = $translationService->request('gemini', 'de', $labels, []);

        self::assertCount(1, $this->requests, 'Remaining batches must not be sent during an outage');
        self::assertSame(['status' => false, 'content' => [], 'message' => 'translation.provider.server_error (HTTP 503)', 'title' => null], $result);
    }
}

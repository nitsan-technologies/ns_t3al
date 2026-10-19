<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Tests\Unit\Service;

use NITSAN\NsT3AL\Service\AiProviderResolver;
use NITSAN\NsT3AL\Service\DeepLService;
use NITSAN\NsT3AL\Service\GeminiService;
use NITSAN\NsT3AL\Service\GoogleService;
use NITSAN\NsT3AL\Service\OpenAiService;
use NITSAN\NsT3AL\Service\TranslationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Localization\Locales;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class TranslationServiceTest extends UnitTestCase
{
    /** @var list<array<string|int, string>> Batches received by the fake provider */
    private array $batches = [];

    /**
     * @param \Closure(array<string|int, string>, int): array $respond Provider response per batch (batch, 0-based call index)
     */
    private function createSubject(\Closure $respond, string $provider = 'openai'): TranslationService
    {
        $fake = function (array $labels) use ($respond): array {
            $this->batches[] = $labels;
            return $respond($labels, count($this->batches) - 1);
        };
        $services = [
            'openai' => $this->createStub(OpenAiService::class),
            'deepl' => $this->createStub(DeepLService::class),
            'gemini' => $this->createStub(GeminiService::class),
            'google' => $this->createStub(GoogleService::class),
        ];
        $services[$provider]->method('translateRequest')->willReturnCallback(
            static fn (string $iso, mixed $second, mixed ...$rest): array => $fake(is_array($second) ? $second : $rest[0])
        );
        $locales = $this->createStub(Locales::class);
        $locales->method('getLanguages')->willReturn(['de' => 'German']);

        return new class (...array_values($services), ...[$locales, new AiProviderResolver($this->createStub(ExtensionConfiguration::class))]) extends TranslationService {
            protected function translate(string $id): string
            {
                return $id === 'translation.partialFailure.message' ? '%d of %d failed: %s' : $id;
            }
        };
    }

    private static function translateAll(): \Closure
    {
        return static fn (array $batch): array => [
            'status' => 'true',
            'content' => array_map(static fn (string $source): string => 'DE ' . $source . ' ', $batch),
        ];
    }

    /**
     * @return array<string, array{source: string, target: string}>
     */
    private static function requestData(int $count): array
    {
        $data = [];
        for ($index = 1; $index <= $count; $index++) {
            $data['label.' . $index] = ['source' => 'Label ' . $index, 'target' => ''];
        }
        return $data;
    }

    #[Test]
    public function labelsAreSentInBatchesOfFiftyWithTheirKeys(): void
    {
        $requestData = self::requestData(120);
        $result = $this->createSubject(self::translateAll())->request('openai', 'de', $requestData, ['openai' => 'gpt-test']);

        self::assertSame([50, 50, 20], array_map('count', $this->batches));
        self::assertSame(array_keys($requestData), array_merge(...array_map('array_keys', $this->batches)));
        self::assertSame(['status' => true, 'content' => array_map(
            static fn (array $unit): array => ['source' => $unit['source'], 'target' => 'DE ' . $unit['source']],
            $requestData
        )], $result);
    }

    public static function providers(): array
    {
        return ['openai' => ['openai'], 'deepl' => ['deepl'], 'gemini' => ['gemini'], 'google' => ['google']];
    }

    #[Test]
    #[DataProvider('providers')]
    public function everyProviderReceivesTheBatches(string $provider): void
    {
        $result = $this->createSubject(self::translateAll(), $provider)->request($provider, 'de', self::requestData(51), []);

        self::assertSame([50, 1], array_map('count', $this->batches));
        self::assertTrue($result['status']);
    }

    #[Test]
    public function untranslatableRowsAreNotSentButKept(): void
    {
        $requestData = [
            'a' => ['source' => 'A', 'target' => ''],
            'blank' => ['source' => '   ', 'target' => 'kept'],
            'missing' => ['target' => 'also kept'],
        ];
        $result = $this->createSubject(self::translateAll())->request('openai', 'de', $requestData, []);

        self::assertSame([['a' => 'A']], $this->batches);
        self::assertSame(['a' => ['source' => 'A', 'target' => 'DE A'], 'blank' => $requestData['blank'], 'missing' => $requestData['missing']], $result['content']);
    }

    #[Test]
    public function nothingToTranslateMakesNoProviderCall(): void
    {
        $subject = $this->createSubject(self::translateAll());
        $blank = ['a' => ['source' => ' ', 'target' => '']];

        self::assertSame(['status' => true, 'content' => $blank], $subject->request('openai', 'de', $blank, []));
        self::assertSame(['status' => false, 'content' => []], $subject->request('openai', 'de', [], []));
        self::assertSame([], $this->batches);
    }

    public static function invalidResponses(): array
    {
        return [
            'missing key' => [static fn (array $batch): array => array_slice($batch, 1, null, true)],
            'extra key' => [static fn (array $batch): array => $batch + ['extra' => 'X']],
            'renamed key' => [static fn (array $batch): array => ['other' => 'X'] + array_slice($batch, 1, null, true)],
            'empty text' => [static fn (array $batch): array => array_map(static fn (): string => ' ', $batch)],
            'non string text' => [static fn (array $batch): array => array_map(static fn (): array => ['x'], $batch)],
            'not an array' => [static fn (): string => 'Hallo'],
        ];
    }

    #[Test]
    #[DataProvider('invalidResponses')]
    public function batchIsRejectedUnlessTheResponseHasExactlyTheRequestedKeys(\Closure $content): void
    {
        $subject = $this->createSubject(static fn (array $batch): array => ['status' => 'true', 'content' => $content($batch)]);

        self::assertSame(
            ['status' => false, 'content' => [], 'message' => 'translation.responseMismatch.message', 'title' => null],
            $subject->request('openai', 'de', self::requestData(3), [])
        );
    }

    #[Test]
    public function rejectedBatchDoesNotDropTheOtherBatches(): void
    {
        $subject = $this->createSubject(static fn (array $batch, int $call): array => $call === 1
            ? ['status' => 'true', 'content' => array_slice($batch, 1, null, true)]
            : self::translateAll()($batch));
        $result = $subject->request('openai', 'de', self::requestData(120), []);

        self::assertTrue($result['status']);
        self::assertCount(70, $result['content']);
        self::assertArrayNotHasKey('label.51', $result['content']);
        self::assertSame('DE Label 101', $result['content']['label.101']['target']);
        self::assertSame('translation.partialFailure.title', $result['title']);
        self::assertSame('50 of 120 failed: translation.responseMismatch.message', $result['message']);
    }

    #[Test]
    public function providerOutageStopsTheRemainingBatches(): void
    {
        $subject = $this->createSubject(static fn (array $batch, int $call): array => $call === 1
            ? ['status' => 'false', 'message' => 'Service busy', 'retryLater' => true]
            : self::translateAll()($batch));
        $result = $subject->request('openai', 'de', self::requestData(160), []);

        self::assertCount(2, $this->batches);
        self::assertCount(50, $result['content']);
        self::assertSame('110 of 160 failed: Service busy', $result['message']);
    }

    #[Test]
    public function providerErrorIsReturnedWithItsTitle(): void
    {
        $subject = $this->createSubject(static fn (): array => ['status' => 'false', 'message' => 'Quota exceeded', 'title' => 'OpenAI']);

        self::assertSame(
            ['status' => false, 'content' => [], 'message' => 'Quota exceeded', 'title' => 'OpenAI'],
            $subject->request('openai', 'de', self::requestData(2), [])
        );
    }

    #[Test]
    public function providerExceptionIsLoggedAndReportedAsFailure(): void
    {
        $subject = $this->createSubject(static fn (): array => throw new \RuntimeException('timeout'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('Translation batch failed', self::callback(
            static fn (array $context): bool => $context['apiModel'] === 'openai' && str_contains($context['exception'], 'timeout')
        ));
        $subject->setLogger($logger);

        $result = $subject->request('openai', 'de', self::requestData(2), []);

        self::assertFalse($result['status']);
        self::assertSame('translation.generation.error', $result['message']);
    }

    #[Test]
    public function unsupportedModelIsReported(): void
    {
        $result = $this->createSubject(self::translateAll())->request('unknown', 'de', self::requestData(1), []);

        self::assertSame('Unsupported AI model.', $result['message']);
        self::assertSame([], $this->batches);
    }
}

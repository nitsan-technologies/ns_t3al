<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Service;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Localization\Locales;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

class TranslationService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const BATCH_SIZE = 50;

    private const KEY_MISSING_LABELS = [
        'openai' => 'setting.openai.key_not_availble',
        'gemini' => 'setting.gemini.key_not_availble',
        'deepl' => 'setting.deepl.key_not_availble',
        'google' => 'setting.google-translate.key_not_availble',
    ];

    protected $openAiService;

    protected $deepLService;

    protected $geminiService;

    protected $googleService;

    protected Locales $locales;

    protected AiProviderResolver $aiProviderResolver;

    public function __construct(
        OpenAiService $openAiService,
        DeepLService $deepLService,
        GeminiService $geminiService,
        GoogleService $googleService,
        Locales $locales,
        AiProviderResolver $aiProviderResolver
    ) {
        $this->openAiService = $openAiService;
        $this->deepLService = $deepLService;
        $this->geminiService = $geminiService;
        $this->googleService = $googleService;
        $this->locales = $locales;
        $this->aiProviderResolver = $aiProviderResolver;
    }

    /**
     * Translates in batches; a batch is only accepted when the provider returns exactly the
     * requested keys with non-empty strings, so a translation can never land on a wrong key.
     *
     * @param array<string|int, array{source?: string, target?: string}> $requestData
     * @return array{status: bool, content: array<string|int, array>, message?: string, title?: string|null}
     */
    public function request(string $apiModel, string $isoCode, array $requestData, array $selectedModels): array
    {
        $langTitle = (string)($this->locales->getLanguages()[$isoCode] ?? $isoCode);

        $translatable = [];
        foreach ($requestData as $key => $unit) {
            $source = (string)($unit['source'] ?? '');
            if (trim($source) !== '') {
                $translatable[$key] = $source;
            }
        }
        if ($translatable === []) {
            return ['status' => $requestData !== [], 'content' => $requestData];
        }

        $translated = [];
        $failedCount = 0;
        $failure = null;
        foreach (array_chunk($translatable, self::BATCH_SIZE, true) as $index => $batch) {
            $result = $this->requestBatch($apiModel, $isoCode, $langTitle, $batch, $selectedModels);
            $providerSucceeded = ($result['status'] ?? '') === 'true';
            $targets = $providerSucceeded ? $this->validateBatch($batch, $result['content'] ?? null) : null;
            if ($targets === null) {
                $failedCount += count($batch);
                $failure ??= $providerSucceeded
                    ? ['message' => $this->translate('translation.responseMismatch.message'), 'title' => null]
                    : ['message' => (string)($result['message'] ?? ''), 'title' => $result['title'] ?? null];
                if (!empty($result['retryLater'])) {
                    // Provider outage: do not let the user wait for every remaining batch to time out.
                    $failedCount += max(0, count($translatable) - ($index + 1) * self::BATCH_SIZE);
                    break;
                }
                continue;
            }
            $translated += $targets;
        }

        if ($translated === []) {
            return [
                'status' => false,
                'content' => [],
                'message' => ($failure['message'] ?? '') !== '' ? $failure['message'] : $this->translate('translation.generation.error'),
                'title' => $failure['title'] ?? null,
            ];
        }

        $content = [];
        foreach ($requestData as $key => $unit) {
            if (isset($translated[$key])) {
                $content[$key] = array_merge((array)$unit, ['target' => $translated[$key]]);
            } elseif (!isset($translatable[$key])) {
                $content[$key] = $unit;
            }
        }

        $responseData = ['status' => true, 'content' => $content];
        if ($failedCount > 0) {
            $responseData['title'] = $this->translate('translation.partialFailure.title');
            $responseData['message'] = sprintf(
                $this->translate('translation.partialFailure.message'),
                $failedCount,
                count($translatable),
                ($failure['message'] ?? '') !== '' ? $failure['message'] : $this->translate('translation.generation.error')
            );
        }
        return $responseData;
    }

    /**
     * @param array<string|int, string> $batch
     */
    private function requestBatch(string $apiModel, string $isoCode, string $langTitle, array $batch, array $selectedModels): array
    {
        try {
            return match ($apiModel) {
                'openai' => $this->openAiService->translateRequest($isoCode, $langTitle, $batch, (string)($selectedModels['openai'] ?? '')),
                'deepl' => $this->deepLService->translateRequest($isoCode, $batch),
                'gemini' => $this->geminiService->translateRequest($isoCode, $langTitle, $batch, (string)($selectedModels['gemini'] ?? '')),
                'google' => $this->googleService->translateRequest($isoCode, $batch),
                default => ['status' => 'false', 'message' => 'Unsupported AI model.'],
            };
        } catch (\Throwable $exception) {
            $this->logger?->error('Translation batch failed', [
                'apiModel' => $apiModel,
                'isoCode' => $isoCode,
                'exception' => $exception::class . ': ' . $exception->getMessage(),
            ]);
            return ['status' => 'false', 'message' => $this->translate('translation.generation.error')];
        }
    }

    /**
     * @param array<string|int, string> $batch
     * @return array<string|int, string>|null Null unless the response has exactly the batch keys, each a non-empty string
     */
    private function validateBatch(array $batch, mixed $translations): ?array
    {
        if (!is_array($translations) || count($translations) !== count($batch)) {
            return null;
        }
        $targets = [];
        foreach ($batch as $key => $source) {
            $target = $translations[$key] ?? null;
            if (!is_string($target) || trim($target) === '') {
                return null;
            }
            $targets[$key] = trim($target);
        }
        return $targets;
    }

    protected function translate(string $id): string
    {
        return (string)(LocalizationUtility::translate($id, 'ns_t3al') ?? $id);
    }

    /**
     * @return array{status: bool, message: string} message = the provider's "API key missing" text
     */
    public function validate(string $apiModel): array
    {
        $label = self::KEY_MISSING_LABELS[$apiModel] ?? null;
        return [
            'status' => $label !== null && $this->aiProviderResolver->isConfigured($apiModel),
            'message' => $label !== null ? (string)LocalizationUtility::translate($label, 'ns_t3al') : '',
        ];
    }
}

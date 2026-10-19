<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Service;

use NITSAN\NsT3AL\Utility\LabelBatchUtility;
use Psr\Log\LoggerAwareInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

class GeminiService implements LoggerAwareInterface
{
    use ProviderErrorTrait;

    private const API_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/';

    /** Current stable default for translation workloads. */
    private const DEFAULT_MODEL = 'gemini-2.5-flash';

    /**
     * Models offered in the Translate toolbar and Extension Settings; anything else is never sent.
     *
     * @var list<string>
     */
    private const ALLOWED_MODELS = [
        'gemini-2.5-flash',
        'gemini-2.5-flash-lite',
        'gemini-3.6-flash',
    ];

    private const HTTP_TIMEOUT = 20;

    private const HTTP_CONNECT_TIMEOUT = 5;

    protected string $apiKey;

    protected string $configuredModel;

    protected RequestFactory $requestFactory;

    public function __construct(
        RequestFactory $requestFactory,
        ExtensionConfiguration $extensionConfiguration
    ) {
        $this->requestFactory = $requestFactory;
        $extConfig = $extensionConfiguration->get('ns_t3al') ?? [];
        $this->apiKey = (string)($extConfig['geminiApiKey'] ?? '');
        $this->configuredModel = (string)($extConfig['geminiApiModel'] ?? '');
    }

    /**
     * @param array<string|int, string> $labels Label key => English source
     * @return array{status: string, content?: array<string|int, mixed>, message?: string, retryLater?: bool}
     */
    public function translateRequest(string $isoCode, string $langTitle, array $labels, string $selectedModel = ''): array
    {
        if ($this->apiKey === '') {
            return [
                'status' => 'false',
                'message' => 'Gemini API key is missing.',
            ];
        }

        $model = $this->resolveModel($selectedModel);
        $apiUrl = self::API_ENDPOINT . rawurlencode($model) . ':generateContent';
        try {
            $jsonPayload = json_encode($this->buildRequestPayload($isoCode, $langTitle, $labels), JSON_THROW_ON_ERROR);
            $response = $this->requestFactory->request(
                $apiUrl,
                'POST',
                [
                    'timeout' => self::HTTP_TIMEOUT,
                    'connect_timeout' => self::HTTP_CONNECT_TIMEOUT,
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'x-goog-api-key' => $this->apiKey,
                    ],
                    'body' => $jsonPayload,
                    'http_errors' => false,
                ]
            );

            return $this->processResponse($response);
        } catch (\Throwable $e) {
            return $this->providerFailure($e);
        }
    }

    /**
     * First allowed model of: request, Extension Settings; otherwise the default.
     */
    private function resolveModel(string $selectedModel): string
    {
        foreach ([$selectedModel, $this->configuredModel] as $candidate) {
            $candidate = trim($candidate);
            if (in_array($candidate, self::ALLOWED_MODELS, true)) {
                return $candidate;
            }
        }

        return self::DEFAULT_MODEL;
    }

    private function processResponse($response): array
    {
        try {
            $statusCode = $response->getStatusCode();
            $responseBody = $response->getBody()->getContents();
            $responseArray = json_decode($responseBody, true);

            // http_errors is off, so outages arrive here instead of as an exception in providerFailure().
            if ($this->isRetryLaterStatus($statusCode)) {
                return $this->providerHttpFailure(
                    $statusCode,
                    is_array($responseArray) ? (string)($responseArray['error']['message'] ?? '') : mb_substr($responseBody, 0, 200)
                );
            }

            if (!is_array($responseArray)) {
                return [
                    'status' => 'false',
                    'message' => 'Invalid response from Gemini API (HTTP ' . $statusCode . ').',
                ];
            }

            if (isset($responseArray['error'])) {
                $errorMessage = (string)($responseArray['error']['message'] ?? 'Gemini API request failed.');
                $errorCode = $responseArray['error']['code'] ?? $statusCode;

                return [
                    'status' => 'false',
                    'message' => trim($errorCode . ': ' . $errorMessage),
                ];
            }

            if ($statusCode < 200 || $statusCode >= 300) {
                return [
                    'status' => 'false',
                    'message' => 'Gemini API request failed with HTTP ' . $statusCode . '.',
                ];
            }

            $generatedText = '';
            foreach ((array)($responseArray['candidates'][0]['content']['parts'] ?? []) as $part) {
                if (is_array($part) && empty($part['thought'])) {
                    $generatedText .= (string)($part['text'] ?? '');
                }
            }
            if ($generatedText === '') {
                $blockReason = $responseArray['promptFeedback']['blockReason']
                    ?? $responseArray['candidates'][0]['finishReason']
                    ?? null;

                return [
                    'status' => 'false',
                    'message' => $blockReason
                        ? 'Gemini returned no translation content (' . $blockReason . ').'
                        : 'Gemini returned no translation content.',
                ];
            }

            $translations = LabelBatchUtility::decodeLabels($generatedText);
            if ($translations === null) {
                $finishReason = (string)($responseArray['candidates'][0]['finishReason'] ?? '');
                return [
                    'status' => 'false',
                    'message' => 'Gemini returned no valid JSON translation'
                        . ($finishReason !== '' && $finishReason !== 'STOP' ? ' (' . $finishReason . ').' : '.'),
                ];
            }

            return [
                'status' => 'true',
                'content' => $translations,
            ];
        } catch (\Throwable $e) {
            return $this->providerFailure($e);
        }
    }

    /**
     * @param array<string|int, string> $labels
     */
    private function buildRequestPayload(string $isoCode, string $langTitle, array $labels): array
    {
        // Gemini expects contents as a list of Content objects.
        // Avoid temperature/topP/topK — Gemini 3.x rejects those sampling params.
        return [
            'systemInstruction' => [
                'parts' => [
                    ['text' => LabelBatchUtility::buildInstructions($langTitle, strtolower($isoCode))],
                ],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => LabelBatchUtility::encodeLabels($labels)],
                    ],
                ],
            ],
            'generationConfig' => [
                'maxOutputTokens' => 8192,
                // No responseSchema: per-key schemas for 50 labels can exceed Gemini's schema
                // complexity limit; TranslationService verifies the returned keys instead.
                'responseMimeType' => 'application/json',
            ],
            'safetySettings' => [
                [
                    'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
                    'threshold' => 'BLOCK_NONE',
                ],
            ],
        ];
    }
}

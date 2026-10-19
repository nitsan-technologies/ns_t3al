<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Service;

use NITSAN\NsT3AL\Utility\LabelBatchUtility;
use Psr\Log\LoggerAwareInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

class OpenAiService implements LoggerAwareInterface
{
    use ProviderErrorTrait;

    private const OPENAI_ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    private const DEFAULT_MODEL = 'gpt-5.6-terra';

    private const HTTP_TIMEOUT = 20;

    private const HTTP_CONNECT_TIMEOUT = 5;

    /**
     * Supported chat models for translation.
     *
     * @var list<string>
     */
    private const ALLOWED_MODELS = [
        'gpt-5.6-terra',
        'gpt-5.6-luna',
        'gpt-5.6-sol',
        'gpt-4o',
    ];

    public array $extConf;

    protected RequestFactory $requestFactory;

    public function __construct(
        RequestFactory $requestFactory,
        ExtensionConfiguration $extensionConfiguration
    ) {
        $this->requestFactory = $requestFactory;
        $this->extConf = $extensionConfiguration->get('ns_t3al') ?? [];
    }

    /**
     * @param array<string|int, string> $labels Label key => English source
     * @return array{status: string, content?: array<string|int, mixed>, message?: string, retryLater?: bool}
     */
    public function translateRequest(string $isoCode, string $langTitle, array $labels, string $selectedModel = ''): array
    {
        try {
            $jsonContent = [
                'model' => $this->resolveModel($selectedModel),
                'messages' => [
                    ['role' => 'system', 'content' => LabelBatchUtility::buildInstructions($langTitle, strtolower($isoCode))],
                    ['role' => 'user', 'content' => LabelBatchUtility::encodeLabels($labels)],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'label_translations',
                        'strict' => true,
                        'schema' => LabelBatchUtility::buildSchema($labels),
                    ],
                ],
            ];

            $response = $this->requestFactory->request(
                self::OPENAI_ENDPOINT,
                'POST',
                [
                    'timeout' => self::HTTP_TIMEOUT,
                    'connect_timeout' => self::HTTP_CONNECT_TIMEOUT,
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer ' . ($this->extConf['openaiApiKey'] ?? ''),
                    ],
                    'json' => $jsonContent,
                ]
            );

            $content = json_decode($response->getBody()->getContents(), true);
            $translations = LabelBatchUtility::decodeLabels((string)($content['choices'][0]['message']['content'] ?? ''));
            if ($translations === null) {
                return [
                    'status' => 'false',
                    'message' => $this->getProviderErrorMessage(),
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
     * Prefer request model, then Extension Settings, then default.
     */
    private function resolveModel(string $selectedModel): string
    {
        $candidate = trim($selectedModel);
        if ($candidate === '') {
            $candidate = trim((string)($this->extConf['openaiApiModel'] ?? ''));
        }
        if ($candidate === '' || !in_array($candidate, self::ALLOWED_MODELS, true)) {
            return self::DEFAULT_MODEL;
        }

        return $candidate;
    }
}

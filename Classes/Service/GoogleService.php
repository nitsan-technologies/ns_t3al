<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Service;

use NITSAN\NsT3AL\Utility\LabelBatchUtility;
use Psr\Log\LoggerAwareInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

class GoogleService implements LoggerAwareInterface
{
    use ProviderErrorTrait;

    private const HTTP_TIMEOUT = 20;

    private const HTTP_CONNECT_TIMEOUT = 5;

    private string $apiKey;

    private string $apiUrl;

    protected RequestFactory $requestFactory;

    public function __construct(
        RequestFactory $requestFactory,
        ExtensionConfiguration $extensionConfiguration
    ) {
        $this->requestFactory = $requestFactory;
        $extConfig = $extensionConfiguration->get('ns_t3al') ?? [];
        $this->apiUrl = (string)($extConfig['googleApiUrl'] ?? '');
        $this->apiKey = (string)($extConfig['googleApiKey'] ?? '');
    }

    /**
     * @param array<string|int, string> $labels Label key => English source
     * @return array{status: string, content?: array<string|int, string>, message?: string, retryLater?: bool}
     */
    public function translateRequest(string $targetLang, array $labels): array
    {
        if ($this->apiKey === '' || trim($this->apiUrl) === '') {
            return [
                'status' => 'false',
                'message' => $this->getProviderErrorMessage(),
            ];
        }

        $requestData = [
            'q' => array_values($labels),
            'target' => $targetLang,
            'format' => 'text',
        ];

        try {
            $response = $this->requestFactory->request(trim($this->apiUrl), 'POST', [
                'timeout' => self::HTTP_TIMEOUT,
                'connect_timeout' => self::HTTP_CONNECT_TIMEOUT,
                'json' => $requestData,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $this->apiKey,
                ],
            ]);
            $responseArray = json_decode($response->getBody()->getContents(), true);
            $translations = LabelBatchUtility::combineWithKeys(
                $labels,
                array_column((array)($responseArray['data']['translations'] ?? []), 'translatedText')
            );
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
}

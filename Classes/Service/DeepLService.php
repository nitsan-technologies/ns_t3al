<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Service;

use NITSAN\NsT3AL\Utility\LabelBatchUtility;
use Psr\Log\LoggerAwareInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

class DeepLService implements LoggerAwareInterface
{
    use ProviderErrorTrait;

    private const HTTP_TIMEOUT = 20;

    private const HTTP_CONNECT_TIMEOUT = 5;

    protected string $apiUrl;

    protected string $apiKey;

    protected RequestFactory $requestFactory;

    protected DeepLLanguageProvider $languageProvider;

    public function __construct(
        RequestFactory $requestFactory,
        ExtensionConfiguration $extensionConfiguration,
        DeepLLanguageProvider $languageProvider
    ) {
        $this->requestFactory = $requestFactory;
        $this->languageProvider = $languageProvider;
        $extConfig = $extensionConfiguration->get('ns_t3al') ?? [];
        $this->apiUrl = (string)($extConfig['deeplApiUrl'] ?? 'https://api-free.deepl.com/v2/translate');
        $this->apiKey = (string)($extConfig['deeplApiKey'] ?? '');
    }

    /**
     * @param string $targetLangIso TYPO3 language key, e.g. "de", "pt_BR", "zh"
     * @param array<string|int, string> $labels Label key => English source
     * @return array{status: string, content?: array<string|int, string>, message?: string, title?: string, retryLater?: bool}
     */
    public function translateRequest(string $targetLangIso, array $labels): array
    {
        $targetLang = DeepLLanguageMapper::resolve(
            $targetLangIso,
            $this->languageProvider->getTargetLanguages($this->apiUrl, $this->apiKey)
        );
        if ($targetLang === null) {
            return [
                'status' => 'false',
                'message' => sprintf($this->translateProviderLabel('translation.deepL.unsupported_language.message'), $targetLangIso),
                'title' => $this->translateProviderLabel('translation.deepL.error.unsupported_languages'),
            ];
        }

        return $this->translateTexts($targetLang, $labels);
    }

    /**
     * @param string $targetLang DeepL target code, e.g. "DE", "PT-BR"
     * @param array<string|int, string> $labels
     */
    protected function translateTexts(string $targetLang, array $labels): array
    {
        try {
            $response = $this->requestFactory->request($this->apiUrl, 'POST', [
                'timeout' => self::HTTP_TIMEOUT,
                'connect_timeout' => self::HTTP_CONNECT_TIMEOUT,
                'json' => [
                    'text' => array_values($labels),
                    'target_lang' => $targetLang,
                ],
                'headers' => [
                    'Authorization' => 'DeepL-Auth-Key ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            $translations = LabelBatchUtility::combineWithKeys(
                $labels,
                array_column((array)($result['translations'] ?? []), 'text')
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

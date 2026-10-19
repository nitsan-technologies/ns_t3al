<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Service;

use GuzzleHttp\Exception\BadResponseException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Crowdin API client. Public methods never throw: HTTP / network errors are logged and reported
 * through their return value, because uploads run after the local XLF file was already written.
 */
class CrowdinService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const DEFAULT_API_URL = 'https://api.crowdin.com/api/v2/';

    /** Crowdin directory that holds the TYPO3 system extensions (TYPO3 Core project layout). */
    public const DEFAULT_SYSTEM_EXTENSION_PATH = '/main/typo3/sysext';

    /** Language files inside an extension directory. */
    public const LANGUAGE_DIRECTORY = 'Resources/Private/Language';

    /** Largest page size the Crowdin API v2 accepts. */
    public const PAGE_LIMIT = 500;

    /** Safety cap: at most PAGE_LIMIT * MAX_PAGES items are read per list request. */
    private const MAX_PAGES = 100;

    private const HTTP_TIMEOUT = 20;

    private const HTTP_CONNECT_TIMEOUT = 5;

    /**
     * TYPO3 language keys whose Crowdin language id is not simply the key with "_" replaced by "-".
     * Crowdin uses region ids for these languages (https://developer.crowdin.com/language-codes/).
     */
    private const LANGUAGE_ID_MAP = [
        'ch' => 'zh-CN',
        'es' => 'es-ES',
        'ga' => 'ga-IE',
        'gu' => 'gu-IN',
        'hy' => 'hy-AM',
        'ne' => 'ne-NP',
        'nn' => 'nn-NO',
        'pa' => 'pa-IN',
        'pt' => 'pt-PT',
        'sv' => 'sv-SE',
        'zh' => 'zh-CN',
    ];

    public array $extConf;

    protected string $accessToken;

    protected string $apiUrl;

    protected string $systemExtensionPath;

    protected RequestFactory $requestFactory;

    /** HTTP status of the last failed request (0 = unreachable), null when no request failed yet. */
    private ?int $lastErrorStatus = null;

    public function __construct(
        RequestFactory $requestFactory,
        ExtensionConfiguration $extensionConfiguration
    ) {
        $this->requestFactory = $requestFactory;
        $extConf = $extensionConfiguration->get('ns_t3al');
        $this->extConf = is_array($extConf) ? $extConf : [];
        $this->accessToken = trim((string)($this->extConf['accessToken'] ?? ''));
        $this->apiUrl = self::normalizeApiUrl((string)($this->extConf['crowdinApiUrl'] ?? ''));
        $this->systemExtensionPath = self::normalizeDirectoryPath((string)($this->extConf['crowdinSystemExtensionPath'] ?? ''));
        if ($this->logger === null) {
            $this->setLogger(
                GeneralUtility::makeInstance(LogManager::class)
                    ->getLogger(static::class)
            );
        }
    }

    public function hasAccessToken(): bool
    {
        return $this->accessToken !== '';
    }

    /**
     * Base URL with exactly one trailing slash; empty falls back to the public Crowdin API.
     */
    public static function normalizeApiUrl(string $url): string
    {
        $url = trim($url);
        return ($url === '' ? rtrim(self::DEFAULT_API_URL, '/') : rtrim($url, '/')) . '/';
    }

    /**
     * Absolute Crowdin directory path without trailing slash, e.g. "/main/typo3/sysext".
     */
    public static function normalizeDirectoryPath(string $path): string
    {
        $path = trim(trim($path), '/');
        return $path === '' ? self::DEFAULT_SYSTEM_EXTENSION_PATH : '/' . $path;
    }

    /**
     * Crowdin language id for a TYPO3 language key: "pt_BR" → "pt-BR", "de" → "de", "es" → "es-ES".
     */
    public static function toCrowdinLanguageId(string $languageKey): string
    {
        $languageKey = trim($languageKey);
        return self::LANGUAGE_ID_MAP[strtolower($languageKey)] ?? str_replace('_', '-', $languageKey);
    }

    /**
     * Picks the project's target language for a TYPO3 language key. Without a target list the mapped id is used.
     *
     * @param list<string> $targetLanguageIds
     */
    public static function resolveLanguageId(string $languageKey, array $targetLanguageIds): ?string
    {
        $mapped = self::toCrowdinLanguageId($languageKey);
        if ($targetLanguageIds === []) {
            return $mapped;
        }
        $byLowercase = [];
        foreach ($targetLanguageIds as $id) {
            $byLowercase[strtolower((string)$id)] = (string)$id;
        }
        $base = strtolower((string)preg_replace('/[-_].*$/', '', $mapped));
        foreach ([strtolower($mapped), strtolower(str_replace('_', '-', $languageKey)), $base] as $candidate) {
            if (isset($byLowercase[$candidate])) {
                return $byLowercase[$candidate];
            }
        }
        // A plain key ("fr") matches the project's only regional variant ("fr-FR"); several variants are ambiguous.
        if (!str_contains($languageKey, '_') && !str_contains($languageKey, '-')) {
            $variants = array_values(array_filter($byLowercase, static fn (string $key): bool => str_starts_with($key, $base . '-'), ARRAY_FILTER_USE_KEY));
            if (count($variants) === 1) {
                return $variants[0];
            }
        }
        return null;
    }

    /**
     * Uploads one translated XLF of a TYPO3 system extension to Crowdin.
     *
     * @return array{status: bool, title: string, message: string}
     */
    public function uploadTranslation(int $projectId, string $extensionKey, string $fileName, string $languageKey, string $content): array
    {
        try {
            if ($projectId <= 0) {
                return $this->errorResult('translation.write.crowdin.error.project_not_found', [$projectId]);
            }
            $project = $this->fetchProject($projectId);
            if ($project === null && $this->lastErrorStatus === 404) {
                // Crowdin answers "404 Project Not Found" also for an invalid token: tell both cases apart.
                $this->request('user', 'GET');
            }
            if ($project === null) {
                return $this->requestErrorResult('translation.write.crowdin.error.project_not_found', [$projectId]);
            }
            $targetLanguageIds = array_values(array_map('strval', (array)($project['targetLanguageIds'] ?? [])));
            $languageId = self::resolveLanguageId($languageKey, $targetLanguageIds);
            if ($languageId === null) {
                return $this->errorResult('translation.write.crowdin.error.language_not_in_project', [$languageKey, self::toCrowdinLanguageId($languageKey)]);
            }

            $directoryPath = $this->systemExtensionPath . '/' . $extensionKey;
            $directoryId = $this->fetchDirectory($projectId, $extensionKey);
            if ($directoryId <= 0) {
                return $this->requestErrorResult('translation.write.crowdin.error.file_not_found', [$directoryPath]);
            }
            $filePath = $directoryPath . '/' . self::LANGUAGE_DIRECTORY . '/' . ltrim($fileName, '/');
            $fileId = $this->fetchFile($projectId, $directoryId, $fileName, $extensionKey);
            if ($fileId <= 0) {
                return $this->requestErrorResult('translation.write.crowdin.error.file_not_found', [$filePath]);
            }

            return $this->updateFile([
                'projectId' => $projectId,
                'fileId' => $fileId,
                'filename' => basename($fileName),
                'targetLanguage' => $languageId,
                'content' => $content,
            ]);
        } catch (\Throwable $e) {
            $this->logFailure('uploadTranslation', $e);
            return $this->errorResult('translation.generation.error');
        }
    }

    public function updateFile($data): array
    {
        try {
            $storageId = $this->fetchFileStorage($data['filename'], $data['content']);
            if ($storageId <= 0) {
                return $this->requestErrorResult('translation.write.crowdin.error.message.storage');
            }

            $url = $this->buildPath(['projects', $data['projectId'], 'translations', $data['targetLanguage']]);
            $requestData = [
                'storageId' => $storageId,
                'autoApproveImported' => true,
                'fileId' => $data['fileId'],
            ];
            $response = $this->request($url, 'POST', [], (string)json_encode($requestData));

            if ($response && $response->getStatusCode() === 200) {
                return [
                    'status' => true,
                    'title' => $this->getLocallangTranslation('translation.write.crowdin.success.title'),
                    'message' => $this->getLocallangTranslation('translation.write.crowdin.success.message'),
                ];
            }
            if ($response === null || $this->isAuthStatus($response->getStatusCode())) {
                return $this->requestErrorResult('translation.write.crowdin.error.message', [$response?->getStatusCode() ?? 0]);
            }
            return $this->errorResult('translation.write.crowdin.error.message', [$response->getStatusCode()]);
        } catch (\Throwable $e) {
            $this->logFailure('updateFile', $e);
            return $this->errorResult('translation.generation.error');
        }
    }

    public function fetchFileStorage($fileName, $fileData): int
    {
        $headers = [
            'Content-Type' => 'application/octet-stream',
            'Authorization' => 'Bearer ' . $this->accessToken,
            'Crowdin-API-FileName' => rawurlencode((string)$fileName),
        ];

        try {
            $response = $this->request('storages', 'POST', $headers, (string)$fileData);
            return (int)($this->decodeData($response, 201)['id'] ?? 0);
        } catch (\Throwable $e) {
            $this->logFailure('fetchFileStorage', $e);
            return 0;
        }
    }

    /**
     * Project data (incl. targetLanguageIds) or null on any error.
     */
    public function fetchProject(int $projectId): ?array
    {
        try {
            $response = $this->request($this->buildPath(['projects', $projectId]), 'GET');
            $data = $this->decodeData($response, 200);
            return $data === [] ? null : $data;
        } catch (\Throwable $e) {
            $this->logFailure('fetchProject', $e);
            return null;
        }
    }

    /**
     * Id of the file "<system extension path>/<extensionKey>/Resources/Private/Language/<fileName>" (exact path;
     * $fileName may contain sub folders of the language directory). Searches the extension directory recursively.
     */
    public function fetchFile($projectId, $directoryId, $name, string $extensionKey = ''): int
    {
        $relativePath = ltrim(str_replace('\\', '/', (string)$name), '/');
        if ($relativePath === '' || in_array('..', explode('/', $relativePath), true)) {
            return 0;
        }

        try {
            $items = $this->fetchAll(['projects', $projectId, 'files'], ['directoryId' => $directoryId, 'recursion' => 1]);
            $expectedPath = $extensionKey !== ''
                ? $this->systemExtensionPath . '/' . $extensionKey . '/' . self::LANGUAGE_DIRECTORY . '/' . $relativePath
                : null;
            $suffix = '/' . self::LANGUAGE_DIRECTORY . '/' . $relativePath;
            $suffixMatch = 0;
            $suffixMatches = 0;
            foreach ($items as $item) {
                $path = (string)($item['path'] ?? '');
                if (($item['type'] ?? '') !== 'xliff' || basename($path) !== basename($relativePath)) {
                    continue;
                }
                if ($expectedPath !== null && $path === $expectedPath) {
                    return (int)($item['id'] ?? 0);
                }
                if (str_ends_with($path, $suffix)) {
                    $suffixMatch = (int)($item['id'] ?? 0);
                    $suffixMatches++;
                }
            }
            // Only an unambiguous match is used when the exact path differs (e.g. another branch prefix).
            return $suffixMatches === 1 ? $suffixMatch : 0;
        } catch (\Throwable $e) {
            $this->logFailure('fetchFile', $e);
        }

        return 0;
    }

    /**
     * Id of the directory "<system extension path>/<extensionKey>" (exact path match).
     */
    public function fetchDirectory($projectId, $extensionKey = ''): int
    {
        if ((string)$extensionKey === '') {
            return 0;
        }
        $expectedPath = $this->systemExtensionPath . '/' . $extensionKey;

        try {
            foreach ($this->fetchAll(['projects', $projectId, 'directories'], ['filter' => (string)$extensionKey]) as $item) {
                if (rtrim((string)($item['path'] ?? ''), '/') === $expectedPath) {
                    return (int)($item['id'] ?? 0);
                }
            }
        } catch (\Throwable $e) {
            $this->logFailure('fetchDirectory', $e, [
                'projectId' => $projectId,
                'extensionKey' => $extensionKey,
            ]);
        }

        return 0;
    }

    /**
     * All "data" items of a list endpoint, read page by page (limit / offset).
     *
     * @param list<int|string> $segments
     * @param array<string, int|string> $query
     * @return list<array<string, mixed>>
     */
    private function fetchAll(array $segments, array $query = []): array
    {
        $items = [];
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $url = $this->buildPath($segments, $query + ['limit' => self::PAGE_LIMIT, 'offset' => $page * self::PAGE_LIMIT]);
            $data = $this->decodeData($this->request($url, 'GET'), 200);
            foreach ($data as $entry) {
                if (is_array($entry['data'] ?? null)) {
                    $items[] = $entry['data'];
                }
            }
            if (count($data) < self::PAGE_LIMIT) {
                break;
            }
        }
        return $items;
    }

    /**
     * Returns the response also for 4xx / 5xx (so callers can report the status code);
     * null when Crowdin could not be reached at all.
     */
    private function request(string $url, string $method, array $headers = [], string $body = ''): ?ResponseInterface
    {
        $options = [
            'timeout' => self::HTTP_TIMEOUT,
            'connect_timeout' => self::HTTP_CONNECT_TIMEOUT,
            'headers' => $headers ?: $this->getHeaders(),
            'body' => $body,
        ];

        try {
            $response = $this->requestFactory->request($this->apiUrl . $url, $method, $options);
        } catch (BadResponseException $e) {
            $this->logFailure('request', $e, ['method' => $method, 'url' => $url, 'status' => $e->getResponse()->getStatusCode()]);
            $response = $e->getResponse();
        } catch (\Throwable $e) {
            $this->logFailure('request', $e, ['method' => $method, 'url' => $url]);
            $this->lastErrorStatus = 0;
            return null;
        }
        if ($response->getStatusCode() >= 400) {
            $this->lastErrorStatus = $response->getStatusCode();
        }
        return $response;
    }

    /**
     * API path relative to crowdinApiUrl with URL-encoded segments and query values.
     *
     * @param list<int|string> $segments
     * @param array<string, int|string> $query
     */
    private function buildPath(array $segments, array $query = []): string
    {
        $path = implode('/', array_map(static fn ($segment): string => rawurlencode((string)$segment), $segments));
        return $query === [] ? $path : $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * The "data" member of a JSON response with the expected status code, otherwise [].
     */
    private function decodeData(?ResponseInterface $response, int $expectedStatus): array
    {
        if ($response === null || $response->getStatusCode() !== $expectedStatus) {
            return [];
        }
        $decoded = json_decode((string)$response->getBody(), true);
        return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    }

    private function isAuthStatus(int $status): bool
    {
        return $status === 401 || $status === 403;
    }

    /**
     * Error for a failed lookup: an authentication or connection problem wins over the "not found" message.
     */
    private function requestErrorResult(string $messageId, array $arguments = []): array
    {
        if ($this->lastErrorStatus !== null && $this->isAuthStatus($this->lastErrorStatus)) {
            return $this->errorResult('translation.write.crowdin.error.auth', [$this->lastErrorStatus]);
        }
        if ($this->lastErrorStatus === 0) {
            return $this->errorResult('translation.write.crowdin.error.unreachable');
        }
        return $this->errorResult($messageId, $arguments);
    }

    private function errorResult(string $messageId, array $arguments = []): array
    {
        return [
            'status' => false,
            'title' => $this->getLocallangTranslation('translation.write.crowdin.error.title'),
            'message' => $this->getLocallangTranslation($messageId, $arguments),
        ];
    }

    private function logFailure(string $operation, \Throwable $exception, array $context = []): void
    {
        $this->logger?->error('Crowdin ' . $operation . ' failed', $context + [
            'exception' => $exception::class . ': ' . $exception->getMessage(),
        ]);
    }

    private function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->accessToken,
        ];
    }

    protected function getLocallangTranslation(string $id, array $arguments = []): string
    {
        $label = (string)$this->getLanguageService()->sL('LLL:EXT:ns_t3al/Resources/Private/Language/locallang.xlf:' . $id);
        if (!empty($arguments)) {
            return vsprintf($label, $arguments);
        }
        return $label;
    }

    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}

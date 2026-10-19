<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Service;

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Shared failure handling of the AI translation providers: every error is logged and returned as
 * the provider status array, so callers never see an exception (timeouts, 4xx/5xx, TypeErrors).
 */
trait ProviderErrorTrait
{
    use LoggerAwareTrait;

    /**
     * "retryLater" marks outages (network error, rate limit, 5xx) after which further batches would fail the same way.
     *
     * @return array{status: string, message: string, retryLater: bool}
     */
    protected function providerFailure(\Throwable $exception): array
    {
        $statusCode = $exception instanceof BadResponseException ? $exception->getResponse()->getStatusCode() : null;
        $this->logger?->error('Translation provider request failed', [
            'provider' => static::class,
            'status' => $statusCode,
            'exception' => $exception::class . ': ' . $exception->getMessage(),
        ]);

        $message = match (true) {
            $exception instanceof ConnectException => $this->translateProviderLabel('translation.provider.unreachable'),
            $statusCode !== null => $this->httpStatusMessage($statusCode),
            default => '',
        };
        return [
            'status' => 'false',
            'message' => $message !== '' ? $message : $this->getProviderErrorMessage(),
            'retryLater' => $exception instanceof ConnectException || $this->isRetryLaterStatus($statusCode),
        ];
    }

    /**
     * Same result as providerFailure() for providers that read error responses themselves (http_errors off).
     *
     * @return array{status: string, message: string, retryLater: bool}
     */
    protected function providerHttpFailure(int $statusCode, string $detail): array
    {
        $this->logger?->error('Translation provider request failed', [
            'provider' => static::class,
            'status' => $statusCode,
            'detail' => $detail,
        ]);
        $message = $this->httpStatusMessage($statusCode);
        return [
            'status' => 'false',
            'message' => $message !== '' ? $message : $this->getProviderErrorMessage(),
            'retryLater' => $this->isRetryLaterStatus($statusCode),
        ];
    }

    /**
     * Rate limit and server errors: the next batch, sent right away, would fail the same way.
     */
    protected function isRetryLaterStatus(?int $statusCode): bool
    {
        return $statusCode === 429 || ($statusCode ?? 0) >= 500;
    }

    private function httpStatusMessage(int $statusCode): string
    {
        $label = $statusCode >= 500 ? 'translation.provider.server_error' : 'translation.provider.rejected';
        return sprintf($this->translateProviderLabel($label), $statusCode);
    }

    protected function getProviderErrorMessage(): string
    {
        return $this->translateProviderLabel('translation.generation.error') ?: 'Translation request failed.';
    }

    protected function translateProviderLabel(string $id): string
    {
        return (string)(LocalizationUtility::translate($id, 'ns_t3al') ?? '');
    }
}

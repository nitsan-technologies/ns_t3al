<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Knows the AI providers and their API key settings; picks the provider of a translate request.
 */
final class AiProviderResolver
{
    /** Provider => Extension Settings key of its API key, in fallback order. */
    public const API_KEY_SETTINGS = [
        'openai' => 'openaiApiKey',
        'gemini' => 'geminiApiKey',
        'deepl' => 'deeplApiKey',
        'google' => 'googleApiKey',
    ];

    /** @var array<string, mixed> */
    private array $settings;

    public function __construct(ExtensionConfiguration $extensionConfiguration)
    {
        $this->settings = (array)($extensionConfiguration->get('ns_t3al') ?? []);
    }

    public function isConfigured(string $provider): bool
    {
        $setting = self::API_KEY_SETTINGS[$provider] ?? null;
        return $setting !== null && !empty($this->settings[$setting]);
    }

    /**
     * A known requested provider is kept even without an API key, so the caller shows that provider's
     * "key missing" message instead of silently switching. Otherwise the first configured provider wins.
     *
     * @return string openai|gemini|deepl|google, or '' when nothing usable was requested and no key is configured
     */
    public function resolve(string $requestedProvider = ''): string
    {
        if (isset(self::API_KEY_SETTINGS[$requestedProvider])) {
            return $requestedProvider;
        }
        foreach (array_keys(self::API_KEY_SETTINGS) as $provider) {
            if ($this->isConfigured($provider)) {
                return $provider;
            }
        }
        return '';
    }
}

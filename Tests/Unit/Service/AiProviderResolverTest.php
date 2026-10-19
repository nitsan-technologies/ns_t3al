<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Tests\Unit\Service;

use NITSAN\NsT3AL\Service\AiProviderResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class AiProviderResolverTest extends UnitTestCase
{
    /**
     * @param array<string, string>|null $settings
     */
    private function createResolver(?array $settings): AiProviderResolver
    {
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($settings);
        return new AiProviderResolver($extensionConfiguration);
    }

    public static function resolveCases(): \Generator
    {
        yield 'requested provider with key' => [['deeplApiKey' => 'k'], 'deepl', 'deepl'];
        yield 'requested known provider without key is kept' => [['openaiApiKey' => 'k'], 'google', 'google'];
        yield 'nothing requested: first configured in fallback order' => [['googleApiKey' => 'k', 'geminiApiKey' => 'k'], '', 'gemini'];
        yield 'unknown provider: first configured' => [['deeplApiKey' => 'k'], 'evil', 'deepl'];
        yield 'nothing requested, nothing configured' => [['openaiApiKey' => ''], '', ''];
        yield 'extension not configured' => [null, '', ''];
    }

    /**
     * @param array<string, string>|null $settings
     */
    #[Test]
    #[DataProvider('resolveCases')]
    public function resolvePicksProvider(?array $settings, string $requested, string $expected): void
    {
        self::assertSame($expected, $this->createResolver($settings)->resolve($requested));
    }

    #[Test]
    public function isConfiguredChecksTheProviderKeyOnly(): void
    {
        $resolver = $this->createResolver(['geminiApiKey' => 'k', 'openaiApiKey' => '']);

        self::assertTrue($resolver->isConfigured('gemini'));
        self::assertFalse($resolver->isConfigured('openai'));
        self::assertFalse($resolver->isConfigured('geminiApiKey'));
        self::assertFalse($resolver->isConfigured(''));
    }
}

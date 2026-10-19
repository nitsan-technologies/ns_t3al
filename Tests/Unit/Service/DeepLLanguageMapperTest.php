<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Tests\Unit\Service;

use NITSAN\NsT3AL\Service\DeepLLanguageMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Localization\Locales;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class DeepLLanguageMapperTest extends UnitTestCase
{
    /**
     * TYPO3 language keys DeepL cannot translate into.
     */
    private const UNSUPPORTED_TYPO3_LANGUAGES = ['fo', 'gd', 'kl', 'km', 'lo', 'rw', 'sn'];

    public static function typo3LanguageKeys(): array
    {
        return [
            'English keeps the former EN variant' => ['en', 'EN-GB'],
            'American English' => ['en_US', 'EN-US'],
            'Portuguese keeps the former PT variant' => ['pt', 'PT-PT'],
            'Brazilian Portuguese' => ['pt_BR', 'PT-BR'],
            'TYPO3 zh is Chinese (Traditional)' => ['zh', 'ZH-HANT'],
            'TYPO3 ch is Chinese (Simple)' => ['ch', 'ZH-HANS'],
            'Chinese (Simplified)' => ['zh_CN', 'ZH-HANS'],
            'Chinese (Simplified Han)' => ['zh_Hans_CN', 'ZH-HANS'],
            'Hong Kong falls back to the zh mapping' => ['zh_HK', 'ZH-HANT'],
            'Norwegian is Bokmål' => ['no', 'NB'],
            'Canadian French' => ['fr_CA', 'FR-CA'],
            'unsupported region falls back to the language' => ['de_AT', 'DE'],
            'German' => ['de', 'DE'],
            'Japanese' => ['ja', 'JA'],
            'Korean' => ['ko', 'KO'],
            'Arabic' => ['ar', 'AR'],
            'Vietnamese' => ['vi', 'VI'],
            'surrounding whitespace and letter case' => [' PT_br ', 'PT-BR'],
        ];
    }

    #[Test]
    #[DataProvider('typo3LanguageKeys')]
    public function typo3LanguageKeyResolvesToTheCurrentDeepLTargetCode(string $typo3Key, string $expected): void
    {
        self::assertSame($expected, DeepLLanguageMapper::resolve($typo3Key, DeepLLanguageMapper::FALLBACK_TARGET_LANGUAGES));
    }

    #[Test]
    public function deprecatedBareTargetCodesAreNeverSent(): void
    {
        foreach (['EN', 'PT', 'ZH'] as $deprecated) {
            self::assertNotContains($deprecated, DeepLLanguageMapper::FALLBACK_TARGET_LANGUAGES);
        }
        foreach (['en', 'en_AU', 'pt', 'pt_AO', 'zh', 'zh_SG'] as $typo3Key) {
            self::assertNotContains(DeepLLanguageMapper::resolve($typo3Key, ['EN', 'PT', 'ZH']), ['EN', 'PT', 'ZH'], $typo3Key);
        }
    }

    #[Test]
    public function liveListInBcp47CasingIsMatchedCaseInsensitively(): void
    {
        $live = ['de', 'en-US', 'pt-BR', 'zh-Hant'];

        self::assertSame('PT-BR', DeepLLanguageMapper::resolve('pt_BR', $live));
        self::assertSame('ZH-HANT', DeepLLanguageMapper::resolve('zh', $live));
        self::assertNull(DeepLLanguageMapper::resolve('en', $live), 'en maps to EN-GB, which this account list lacks');
    }

    #[Test]
    public function unsupportedOrEmptyLanguagesResolveToNull(): void
    {
        foreach ([...self::UNSUPPORTED_TYPO3_LANGUAGES, '', 'default'] as $typo3Key) {
            self::assertNull(DeepLLanguageMapper::resolve($typo3Key, DeepLLanguageMapper::FALLBACK_TARGET_LANGUAGES), $typo3Key);
        }
    }

    #[Test]
    public function everyOtherTypo3LanguageCanBeTranslated(): void
    {
        $languages = (new \ReflectionClass(Locales::class))->getProperty('languages')->getDefaultValue();
        foreach (array_keys($languages) as $typo3Key) {
            // TYPO3 12/13 also list the English source as "default", which is never a target.
            if ($typo3Key === 'default' || in_array($typo3Key, self::UNSUPPORTED_TYPO3_LANGUAGES, true)) {
                continue;
            }
            self::assertNotNull(DeepLLanguageMapper::resolve($typo3Key, DeepLLanguageMapper::FALLBACK_TARGET_LANGUAGES), $typo3Key);
        }
    }

    #[Test]
    public function candidatesGoFromSpecificToGeneral(): void
    {
        self::assertSame(['PT-BR', 'PT-PT'], DeepLLanguageMapper::candidates('pt_BR'));
        self::assertSame(['DE-AT', 'DE'], DeepLLanguageMapper::candidates('de-AT'));
        self::assertSame(['NB'], DeepLLanguageMapper::candidates('no'));
    }
}

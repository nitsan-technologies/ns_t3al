<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Service;

/**
 * Maps TYPO3 language keys to DeepL target language codes.
 *
 * DeepL deprecated the bare target codes EN, PT and ZH; they map to the variant DeepL used for them
 * (EN-GB, PT-PT, ZH-HANS), except TYPO3 "zh", which is Chinese (Traditional).
 */
final class DeepLLanguageMapper
{
    /**
     * TYPO3 key (lowercase, "-" separated) => DeepL target code, where the uppercased key is not the DeepL code.
     */
    private const TYPO3_TO_DEEPL = [
        'en' => 'EN-GB',
        'pt' => 'PT-PT',
        'zh' => 'ZH-HANT',
        'ch' => 'ZH-HANS',
        'zh-cn' => 'ZH-HANS',
        'zh-hans-cn' => 'ZH-HANS',
        'no' => 'NB',
    ];

    /**
     * DeepL text translation targets (GET /v3/languages?resource=translate_text, usable_as_target), used when the
     * live list cannot be fetched.
     */
    public const FALLBACK_TARGET_LANGUAGES = [
        'ACE', 'AF', 'AN', 'AR', 'AS', 'AY', 'AZ', 'BA', 'BE', 'BG', 'BHO', 'BN', 'BR', 'BS', 'CA', 'CEB', 'CKB',
        'CS', 'CY', 'DA', 'DE', 'DE-CH', 'DE-DE', 'EL', 'EN-GB', 'EN-US', 'EO', 'ES', 'ES-419', 'ES-ES', 'ET', 'EU',
        'FA', 'FI', 'FR', 'FR-CA', 'FR-FR', 'GA', 'GL', 'GN', 'GOM', 'GU', 'HA', 'HE', 'HI', 'HR', 'HT', 'HU', 'HY',
        'ID', 'IG', 'IS', 'IT', 'JA', 'JV', 'KA', 'KK', 'KMR', 'KO', 'KY', 'LA', 'LB', 'LMO', 'LN', 'LT', 'LV', 'MAI',
        'MG', 'MI', 'MK', 'ML', 'MN', 'MR', 'MS', 'MT', 'MY', 'NB', 'NE', 'NL', 'OC', 'OM', 'PA', 'PAG', 'PAM', 'PL',
        'PRS', 'PS', 'PT-BR', 'PT-PT', 'QU', 'RO', 'RU', 'SA', 'SCN', 'SK', 'SL', 'SQ', 'SR', 'ST', 'SU', 'SV', 'SW',
        'TA', 'TE', 'TG', 'TH', 'TK', 'TL', 'TN', 'TR', 'TS', 'TT', 'UK', 'UR', 'UZ', 'VI', 'WO', 'XH', 'YI', 'YUE',
        'ZH-HANS', 'ZH-HANT', 'ZU',
    ];

    /**
     * DeepL codes to try, most specific first: "pt_BR" → PT-BR, then PT-PT; "de_AT" → DE-AT, then DE.
     *
     * @return list<string>
     */
    public static function candidates(string $typo3LanguageKey): array
    {
        $key = strtolower(str_replace('_', '-', trim($typo3LanguageKey)));
        if ($key === '' || $key === 'default') {
            return [];
        }
        $candidates = [self::TYPO3_TO_DEEPL[$key] ?? strtoupper($key)];
        $base = strstr($key, '-', true);
        if ($base !== false && $base !== '') {
            $candidates[] = self::TYPO3_TO_DEEPL[$base] ?? strtoupper($base);
        }
        return array_values(array_unique($candidates));
    }

    /**
     * @param list<string> $supportedTargets DeepL target codes in any letter case
     * @return string|null Uppercase DeepL target code; null when DeepL cannot translate into the language
     */
    public static function resolve(string $typo3LanguageKey, array $supportedTargets): ?string
    {
        $supported = array_flip(array_map('strtoupper', $supportedTargets));
        foreach (self::candidates($typo3LanguageKey) as $candidate) {
            if (isset($supported[$candidate])) {
                return $candidate;
            }
        }
        return null;
    }
}

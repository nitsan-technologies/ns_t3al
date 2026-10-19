<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Configuration;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Masked input for secrets in Settings → Extension Configuration (ext_conf_template "type=user[…]").
 * Same name / id / CSS as the core string field, so saving works unchanged on TYPO3 12.4 – 14.
 * Core calls user fields with the template default as fieldValue, so the stored value is read here;
 * otherwise saving the form would clear the token.
 */
class PasswordField
{
    /**
     * @param array{fieldName?: string, fieldValue?: mixed} $params
     */
    public function render(array $params): string
    {
        $name = (string)($params['fieldName'] ?? '');
        $value = $this->storedValue($name) ?? (is_scalar($params['fieldValue'] ?? null) ? (string)$params['fieldValue'] : '');
        return sprintf(
            '<input class="form-control" id="em-ns_t3al-%1$s" type="password" name="%1$s" value="%2$s" autocomplete="new-password" spellcheck="false" />',
            htmlspecialchars($name, ENT_QUOTES | ENT_HTML5),
            htmlspecialchars($value, ENT_QUOTES | ENT_HTML5)
        );
    }

    protected function storedValue(string $name): ?string
    {
        if ($name === '') {
            return null;
        }
        try {
            $value = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('ns_t3al', $name);
        } catch (\Throwable) {
            return null;
        }
        return is_scalar($value) ? (string)$value : null;
    }
}

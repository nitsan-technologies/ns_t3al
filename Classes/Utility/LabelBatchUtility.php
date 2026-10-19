<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Utility;

/**
 * Structured (JSON keyed by label key) request/response handling for LLM translation batches.
 */
final class LabelBatchUtility
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    public static function buildInstructions(string $languageTitle, string $isoCode): string
    {
        return <<<PROMPT
You translate TYPO3 CMS interface labels from English to {$languageTitle} (ISO code: {$isoCode}).

The user message is a JSON object: each key is a label identifier, each value is the English text.

Rules:
1. Return a JSON object with exactly the same keys — no added, removed, renamed or merged keys.
2. Translate only the values. Never translate or alter the keys.
3. Keep placeholders (e.g. %s, %d, %1\$s, {0}, {name}), HTML/XML tags, entities and line breaks unchanged.
4. Keep TYPO3-specific terms, product names and code references unless they have an established translation.
5. Return only the JSON object — no explanations.
PROMPT;
    }

    /**
     * @param array<string|int, string> $labels
     */
    public static function encodeLabels(array $labels): string
    {
        return json_encode((object)$labels, self::JSON_FLAGS);
    }

    /**
     * Strict JSON schema requiring exactly the given keys, each a string.
     *
     * @param array<string|int, string> $labels
     * @return array{type: string, properties: object, required: list<string>, additionalProperties: bool}
     */
    public static function buildSchema(array $labels): array
    {
        $keys = array_map('strval', array_keys($labels));
        return [
            'type' => 'object',
            'properties' => (object)array_fill_keys($keys, ['type' => 'string']),
            'required' => $keys,
            'additionalProperties' => false,
        ];
    }

    /**
     * Maps positional results (DeepL/Google string arrays) back to label keys.
     *
     * @param array<string|int, string> $labels
     * @param list<mixed> $translations
     * @return array<string|int, string>|null Null when the result count differs from the request
     */
    public static function combineWithKeys(array $labels, array $translations): ?array
    {
        if (count($translations) !== count($labels)) {
            return null;
        }
        return array_combine(array_keys($labels), array_map(
            static fn (mixed $text): string => is_string($text) ? $text : '',
            array_values($translations)
        ));
    }

    /**
     * @return array<string|int, mixed>|null Null when the text is not a JSON object
     */
    public static function decodeLabels(string $json): ?array
    {
        $json = trim($json);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $json, $matches) === 1) {
            $json = $matches[1];
        }
        if (!str_starts_with($json, '{')) {
            return null;
        }
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($decoded) ? $decoded : null;
    }
}

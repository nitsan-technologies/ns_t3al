<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Utility;

/**
 * Turns the stored log diff (tx_nst3al_domain_model_logs.content) into plain-text segments
 * that the view escapes. Two storage formats exist:
 * - current: {"id": {"target": {"del": "old", "ins": "new"}, "source": {...}}} (plain text)
 * - legacy rows: {"id": {"target": "<del>old</del><ins>new</ins>", ...}} (HTML-escaped text)
 */
final class LogContentNormalizer
{
    private const PARTS = ['target', 'source'];

    /**
     * @return array<string, list<array{type: string, text: string}>> trans-unit id => target segments, then source segments
     */
    public static function normalize(string $content): array
    {
        if (trim($content) === '') {
            return [];
        }
        try {
            $decoded = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }

        $entries = [];
        foreach ($decoded as $id => $parts) {
            if (!is_array($parts)) {
                continue;
            }
            $segments = [];
            foreach (self::PARTS as $part) {
                $value = $parts[$part] ?? null;
                $segments = array_merge(
                    $segments,
                    is_array($value) ? self::segmentsFromParts($value) : self::segmentsFromLegacyMarkup((string)$value)
                );
            }
            if ($segments !== []) {
                $entries[(string)$id] = $segments;
            }
        }
        return $entries;
    }

    /**
     * @param array<string, mixed> $value
     * @return list<array{type: string, text: string}>
     */
    private static function segmentsFromParts(array $value): array
    {
        $segments = [];
        foreach (['del', 'ins'] as $type) {
            if (array_key_exists($type, $value) && is_scalar($value[$type])) {
                $segments[] = ['type' => $type, 'text' => (string)$value[$type]];
            }
        }
        return $segments;
    }

    /**
     * @return list<array{type: string, text: string}>
     */
    private static function segmentsFromLegacyMarkup(string $markup): array
    {
        if ($markup === '' || preg_match_all('#<(del|ins)>(.*?)</\1>#s', $markup, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }
        $segments = [];
        foreach ($matches as $match) {
            $segments[] = [
                'type' => $match[1],
                'text' => htmlspecialchars_decode($match[2], ENT_QUOTES | ENT_HTML401),
            ];
        }
        return $segments;
    }
}

<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Utility;

/**
 * Label grid rows (key => [langKey => text]) compared and normalised the way the XLF files are
 * written: texts trimmed, line breaks as LF.
 */
final class GridLabels
{
    /**
     * Browsers submit textarea line breaks as CRLF while XLF files store LF. Without this, every
     * multi-line label looks edited and is written back with &#xD; characters.
     *
     * @param array<string|int, mixed> $labels
     * @return array<string|int, mixed>
     */
    public static function normalizeLineBreaks(array $labels): array
    {
        foreach ($labels as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($row as $langKey => $value) {
                if (is_string($value)) {
                    $labels[$key][$langKey] = self::lf($value);
                }
            }
        }
        return $labels;
    }

    /**
     * Whether the grid would change the files: other keys, another row order or another text.
     *
     * @param array<string|int, array<string, string>> $fileLabels
     * @param array<string|int, mixed> $gridLabels
     */
    public static function differ(array $fileLabels, array $gridLabels): bool
    {
        if (array_map('strval', array_keys($fileLabels)) !== array_map('strval', array_keys($gridLabels))) {
            return true;
        }
        foreach ($gridLabels as $key => $row) {
            if (!is_array($row)) {
                return true;
            }
            foreach (array_keys($row + $fileLabels[$key]) as $langKey) {
                if (self::text($row[$langKey] ?? '') !== self::text($fileLabels[$key][$langKey] ?? '')) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim(self::lf((string)$value)) : '';
    }

    private static function lf(string $value): string
    {
        return str_replace(["\r\n", "\r"], "\n", $value);
    }
}

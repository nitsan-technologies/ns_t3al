<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Utility;

/**
 * CSV formula injection guard: spreadsheet apps evaluate cells starting with = + - @ (and tab / CR).
 * Such cells get a leading apostrophe on export (shown as text, the apostrophe is hidden), and
 * import removes exactly one apostrophe again, so export → import is lossless.
 */
final class CsvCellEscaper
{
    /** Formula trigger, optionally behind apostrophes (so a value that already starts with ' round-trips). */
    private const FORMULA_PATTERN = "/^'*[=+\\-@\\t\\r]/";

    public static function escape(string $value): string
    {
        return preg_match(self::FORMULA_PATTERN, $value) === 1 ? "'" . $value : $value;
    }

    public static function unescape(string $value): string
    {
        return str_starts_with($value, "'") && preg_match(self::FORMULA_PATTERN, substr($value, 1)) === 1
            ? substr($value, 1)
            : $value;
    }
}

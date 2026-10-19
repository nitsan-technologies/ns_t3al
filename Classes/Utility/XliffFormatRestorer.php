<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Utility;

/**
 * libxml re-serializes untouched markup differently (`<header />` → `<header/>`, `>` → `&gt;`,
 * multi-line start tags joined, root attribute order, CRLF → LF). The unmodified document is aligned
 * with the original file; every serialized block found unchanged in the edited result is replaced by
 * its original text, so only really edited lines differ from the file on disk.
 */
final class XliffFormatRestorer
{
    private const ALIGN_WINDOW = 40;

    /**
     * @param string $pristine libxml serialization of the original document before any edit
     * @param string $modified libxml serialization after the edits
     */
    public function restore(string $pristine, string $modified, string $originalXml): string
    {
        $isCrlf = str_contains($originalXml, "\r\n");
        if ($isCrlf) {
            $originalXml = str_replace("\r\n", "\n", $originalXml);
        }
        $modified = $this->withOriginalProlog($modified, $originalXml);
        $hunks = $this->alignHunks(
            explode("\n", $originalXml),
            explode("\n", $this->withOriginalProlog($pristine, $originalXml))
        );
        if ($hunks === null) {
            return $isCrlf ? str_replace("\n", "\r\n", $modified) : $modified;
        }

        $replacements = [];
        $ambiguous = [];
        foreach ($hunks as [$serializedLines, $originalLines]) {
            $key = implode("\n", $serializedLines);
            if (isset($replacements[$key]) && $replacements[$key][1] !== $originalLines) {
                $ambiguous[$key] = true;
            }
            $replacements[$key] = [$serializedLines, $originalLines];
        }
        $byFirstLine = [];
        foreach ($replacements as $key => $replacement) {
            if (!isset($ambiguous[$key])) {
                $byFirstLine[$replacement[0][0]][] = $replacement;
            }
        }

        $lines = explode("\n", $modified);
        $result = [];
        for ($index = 0, $count = count($lines); $index < $count;) {
            foreach ($byFirstLine[$lines[$index]] ?? [] as [$serializedLines, $originalLines]) {
                if (array_slice($lines, $index, count($serializedLines)) === $serializedLines) {
                    array_push($result, ...$originalLines);
                    $index += count($serializedLines);
                    continue 2;
                }
            }
            $result[] = $lines[$index++];
        }
        return implode($isCrlf ? "\r\n" : "\n", $result);
    }

    /**
     * Pairs of differing line blocks [serialized lines, original lines] between two nearly identical
     * texts (greedy resynchronisation within a small window). Null when they cannot be aligned.
     *
     * @param list<string> $original
     * @param list<string> $serialized
     * @return list<array{0: list<string>, 1: list<string>}>|null
     */
    private function alignHunks(array $original, array $serialized): ?array
    {
        $hunks = [];
        $i = 0;
        $j = 0;
        $originalCount = count($original);
        $serializedCount = count($serialized);
        while ($i < $originalCount || $j < $serializedCount) {
            if ($i < $originalCount && $j < $serializedCount && $original[$i] === $serialized[$j]) {
                $i++;
                $j++;
                continue;
            }
            $sync = null;
            for ($distance = 1; $distance <= 2 * self::ALIGN_WINDOW && $sync === null; $distance++) {
                for ($k = max(0, $distance - self::ALIGN_WINDOW); $k <= min($distance, self::ALIGN_WINDOW); $k++) {
                    $nextI = $i + $k;
                    $nextJ = $j + $distance - $k;
                    $atEnd = $nextI === $originalCount && $nextJ === $serializedCount;
                    $matches = $nextI < $originalCount && $nextJ < $serializedCount
                        && $original[$nextI] === $serialized[$nextJ]
                        && (($nextI + 1 >= $originalCount && $nextJ + 1 >= $serializedCount)
                            || ($original[$nextI + 1] ?? null) === ($serialized[$nextJ + 1] ?? false));
                    if ($atEnd || $matches) {
                        $sync = [$nextI, $nextJ];
                        break;
                    }
                }
            }
            if ($sync === null) {
                return null;
            }
            if ($sync[1] > $j) {
                $hunks[] = [array_slice($serialized, $j, $sync[1] - $j), array_slice($original, $i, $sync[0] - $i)];
            }
            [$i, $j] = $sync;
        }
        return $hunks;
    }

    /**
     * The root element is never modified: keep the original XML declaration and <xliff> start tag
     * and the original whitespace after the closing root tag.
     */
    private function withOriginalProlog(string $content, string $originalXml): string
    {
        $rootStartTag = '/^.*?<(?:[A-Za-z_][\w.-]*:)?xliff\b[^>]*>/s';
        if (preg_match($rootStartTag, $originalXml, $original) === 1 && preg_match($rootStartTag, $content, $serialized) === 1) {
            $content = $original[0] . substr($content, strlen($serialized[0]));
        }
        return rtrim($content) . substr($originalXml, strlen(rtrim($originalXml)));
    }
}

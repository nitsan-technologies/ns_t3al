<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Utility;

/**
 * Applies grid labels to an existing XLF document in place, so header, notes, attributes
 * (approved, state, xml:space, date), comments and formatting of untouched nodes survive.
 * Only direct <body> trans-units are managed; units inside <group> (plural forms) are left alone.
 */
final class XliffDocumentUpdater
{
    private const DEFAULT_INDENT = "\t";

    public function __construct(
        private readonly XliffFormatRestorer $formatRestorer = new XliffFormatRestorer()
    ) {
    }

    /**
     * @param array<string|int, array{source: string, target: string}> $units Final grid units in grid order
     * @param array<string, string> $renames Old key => new key (renamed in place, keeps position)
     * @param bool $keepGridOrder Arrange the units in the order of $units (rows moved in the grid); ignored with $sortByKey
     * @return array{content: string, changed: bool}|null Null when the existing XML cannot be parsed
     */
    public function update(string $xml, array $units, array $renames, bool $isTargetFile, bool $sortByKey, bool $keepGridOrder = false): ?array
    {
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = true;
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return null;
        }
        $body = (new \DOMXPath($dom))->query('/*[local-name()="xliff"]/*[local-name()="file"]/*[local-name()="body"]')->item(0);
        if (!$body instanceof \DOMElement) {
            return null;
        }

        $pristine = (string)$dom->saveXML();
        $nodes = [];
        $duplicates = [];
        foreach ($this->childElements($body, 'trans-unit') as $unitElement) {
            $id = $unitElement->getAttribute('id');
            if (isset($nodes[$id])) {
                $duplicates[$id][] = $nodes[$id];
            }
            // Last duplicate wins — the same unit the grid (and TYPO3's XliffParser) reads.
            $nodes[$id] = $unitElement;
        }

        $changed = false;
        foreach ($renames as $oldKey => $newKey) {
            $oldKey = (string)$oldKey;
            if ($oldKey === $newKey || $newKey === '' || !isset($nodes[$oldKey]) || isset($nodes[$newKey])) {
                continue;
            }
            foreach ([...($duplicates[$oldKey] ?? []), $nodes[$oldKey]] as $unitElement) {
                if ($unitElement->getAttribute('resname') === $oldKey) {
                    $unitElement->setAttribute('resname', $newKey);
                }
                $unitElement->setAttribute('id', $newKey);
            }
            $nodes[$newKey] = $nodes[$oldKey];
            $duplicates[$newKey] = $duplicates[$oldKey] ?? [];
            unset($nodes[$oldKey], $duplicates[$oldKey]);
            $changed = true;
        }

        foreach ($nodes as $key => $unitElement) {
            if (!array_key_exists($key, $units)) {
                $this->removeUnit($unitElement, $duplicates[$key] ?? []);
                unset($nodes[$key]);
                $changed = true;
            }
        }

        foreach ($units as $key => $unit) {
            $key = (string)$key;
            if (isset($nodes[$key]) && $isTargetFile && trim($unit['target']) === '' && $this->targetText($nodes[$key]) !== '') {
                // Cleared translation: TYPO3 renders a target-file unit without text as an empty label,
                // only a unit missing from the file falls back to English.
                $this->removeUnit($nodes[$key], $duplicates[$key] ?? []);
                unset($nodes[$key]);
                $changed = true;
                continue;
            }
            if (isset($nodes[$key])) {
                $changed = $this->updateUnit($nodes[$key], $unit, $isTargetFile) || $changed;
                continue;
            }
            if ($isTargetFile && trim($unit['target']) === '') {
                // Never add empty <target> elements: TYPO3 would render an empty label instead of English.
                continue;
            }
            $nodes[$key] = $this->appendUnit($body, $key, $unit, $isTargetFile);
            $changed = true;
        }

        if ($sortByKey) {
            $changed = $this->sortUnits($body) || $changed;
        } elseif ($keepGridOrder) {
            $changed = $this->arrangeUnits($body, array_map('strval', array_keys($units))) || $changed;
        }

        return ['content' => $changed ? $this->formatRestorer->restore($pristine, (string)$dom->saveXML(), $xml) : $xml, 'changed' => $changed];
    }

    /**
     * @param array{source: string, target: string} $unit
     */
    private function updateUnit(\DOMElement $unitElement, array $unit, bool $isTargetFile): bool
    {
        $source = $this->childElements($unitElement, 'source')[0] ?? null;
        if (!$isTargetFile) {
            if ($source !== null) {
                return $this->setTextIfChanged($source, $unit['source']);
            }
            $this->insertChild($unitElement, 'source', $unit['source'], null);
            return true;
        }

        $target = $this->childElements($unitElement, 'target')[0] ?? null;
        if ($target !== null) {
            $targetChanged = $this->setTextIfChanged($target, $unit['target']);
        } elseif (trim($unit['target']) !== '') {
            $this->insertChild($unitElement, 'target', $unit['target'], $source?->nextSibling);
            $targetChanged = true;
        } else {
            $targetChanged = false;
        }
        // A target file's <source> is refreshed only together with its translation, so saving
        // one label never rewrites stale sources of untouched units.
        if ($targetChanged && $source !== null) {
            $this->setTextIfChanged($source, $unit['source']);
        }
        return $targetChanged;
    }

    /**
     * Compares like the grid reads values (direct text, trimmed) so inline markup or
     * whitespace differences alone never cause a rewrite.
     */
    private function setTextIfChanged(\DOMElement $element, string $value): bool
    {
        if ($this->directText($element) === trim($value)) {
            return false;
        }
        $isCdata = false;
        foreach ($element->childNodes as $child) {
            $isCdata = $isCdata || $child instanceof \DOMCdataSection;
        }
        while ($element->firstChild !== null) {
            $element->removeChild($element->firstChild);
        }
        $document = $element->ownerDocument;
        $element->appendChild($isCdata && !str_contains($value, ']]>')
            ? $document->createCDATASection($value)
            : $document->createTextNode($value));
        return true;
    }

    /**
     * @param array{source: string, target: string} $unit
     */
    private function appendUnit(\DOMElement $body, string $key, array $unit, bool $isTargetFile): \DOMElement
    {
        $unitElement = $this->createElement($body, 'trans-unit');
        $unitElement->setAttribute('id', $key);
        $unitElement->setAttribute('resname', $key);
        $this->placeInBody($body, $unitElement);
        $this->insertChild($unitElement, 'source', $unit['source'], null);
        if ($isTargetFile) {
            $this->insertChild($unitElement, 'target', $unit['target'], null);
        }
        return $unitElement;
    }

    /**
     * Appends $element as last unit of <body>, before the closing indentation.
     */
    private function placeInBody(\DOMElement $body, \DOMElement $element, ?string $unitIndent = null): void
    {
        $unitIndent ??= $this->detectIndents($body)[0];
        $trailing = $this->whitespaceText($body->lastChild);
        if ($trailing === null) {
            $trailing = $body->appendChild($body->ownerDocument->createTextNode($this->lineIndent($body)));
        }
        $body->insertBefore($body->ownerDocument->createTextNode($unitIndent), $trailing);
        $body->insertBefore($element, $trailing);
    }

    private function insertChild(\DOMElement $unitElement, string $name, string $text, ?\DOMNode $before): void
    {
        $document = $unitElement->ownerDocument;
        [$unitIndent, $childIndent] = $this->detectIndents($unitElement->parentNode instanceof \DOMElement ? $unitElement->parentNode : $unitElement);
        if ($before === null) {
            $before = $this->whitespaceText($unitElement->lastChild)
                ?? $unitElement->appendChild($document->createTextNode($unitIndent));
        }
        $child = $this->createElement($unitElement, $name);
        $child->appendChild($document->createTextNode($text));
        $unitElement->insertBefore($document->createTextNode($childIndent), $before);
        $unitElement->insertBefore($child, $before);
    }

    /**
     * Same key order as the grid's "Sort by key" (ksort).
     */
    private function sortUnits(\DOMElement $body): bool
    {
        $ids = [];
        foreach ($this->childElements($body, 'trans-unit') as $unitElement) {
            $ids[$unitElement->getAttribute('id')] = true;
        }
        ksort($ids);
        return $this->arrangeUnits($body, array_map('strval', array_keys($ids)));
    }

    /**
     * Moves the direct trans-units into the given id order; units whose id is not listed keep their
     * relative order after the listed ones. Duplicate ids move together and keep their relative order,
     * so the last one still wins. Every unit takes its own leading whitespace along (mixed indentation,
     * blank lines), so only the moved units differ in the written file.
     *
     * @param list<string> $order
     */
    private function arrangeUnits(\DOMElement $body, array $order): bool
    {
        $unitElements = $this->childElements($body, 'trans-unit');
        $byKey = [];
        foreach ($unitElements as $unitElement) {
            $byKey[$unitElement->getAttribute('id')][] = $unitElement;
        }
        $groups = [];
        foreach ($order as $id) {
            if (isset($byKey[$id])) {
                $groups[] = $byKey[$id];
                unset($byKey[$id]);
            }
        }
        $arranged = array_merge(...$groups, ...array_values($byKey));
        if ($arranged === $unitElements) {
            return false;
        }
        [$unitIndent] = $this->detectIndents($body);
        $leading = [];
        foreach ($arranged as $unitElement) {
            $leading[spl_object_id($unitElement)] = $this->whitespaceText($unitElement->previousSibling)?->data;
        }
        foreach ($arranged as $unitElement) {
            $this->removeWithIndent($unitElement);
        }
        foreach ($arranged as $unitElement) {
            $this->placeInBody($body, $unitElement, $leading[spl_object_id($unitElement)] ?? $unitIndent);
        }
        return true;
    }

    /**
     * @return array{0: string, 1: string} Indentation before a unit and before a unit's children
     */
    private function detectIndents(\DOMElement $body): array
    {
        $parentIndent = $this->lineIndent($body);
        $firstUnit = $this->childElements($body, 'trans-unit')[0] ?? null;
        $unitIndent = $firstUnit !== null ? $this->indentBefore($firstUnit) : null;
        $unitIndent ??= $parentIndent . self::DEFAULT_INDENT;

        $childIndent = null;
        if ($firstUnit !== null) {
            $firstChild = $this->childElements($firstUnit)[0] ?? null;
            $childIndent = $firstChild !== null ? $this->indentBefore($firstChild) : null;
        }
        $step = str_starts_with($unitIndent, $parentIndent) && strlen($unitIndent) > strlen($parentIndent)
            ? substr($unitIndent, strlen($parentIndent))
            : self::DEFAULT_INDENT;
        return [$unitIndent, $childIndent ?? $unitIndent . $step];
    }

    private function lineIndent(\DOMElement $element): string
    {
        return $this->indentBefore($element) ?? "\n";
    }

    private function indentBefore(\DOMNode $node): ?string
    {
        $previous = $this->whitespaceText($node->previousSibling);
        $lineBreak = $previous !== null ? strrpos($previous->data, "\n") : false;
        return $lineBreak === false ? null : substr($previous->data, $lineBreak);
    }

    /**
     * Trimmed direct text, the way the grid reads a value.
     */
    private function directText(\DOMElement $element): string
    {
        $text = '';
        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $text .= $child->data;
            }
        }
        return trim($text);
    }

    private function targetText(\DOMElement $unitElement): string
    {
        $target = $this->childElements($unitElement, 'target')[0] ?? null;
        return $target !== null ? $this->directText($target) : '';
    }

    /**
     * @param list<\DOMElement> $duplicates Earlier units with the same id; removed too, or one of them would win
     */
    private function removeUnit(\DOMElement $unitElement, array $duplicates): void
    {
        foreach ([...$duplicates, $unitElement] as $element) {
            $this->removeWithIndent($element);
        }
    }

    private function removeWithIndent(\DOMElement $element): void
    {
        $previous = $this->whitespaceText($element->previousSibling);
        $previous?->parentNode?->removeChild($previous);
        $element->parentNode?->removeChild($element);
    }

    private function whitespaceText(?\DOMNode $node): ?\DOMText
    {
        return $node instanceof \DOMText && !$node instanceof \DOMCdataSection && trim($node->data) === '' ? $node : null;
    }

    private function createElement(\DOMElement $context, string $localName): \DOMElement
    {
        $namespace = $context->namespaceURI;
        if ($namespace === null || $namespace === '') {
            return $context->ownerDocument->createElement($localName);
        }
        $prefix = $context->prefix;
        return $context->ownerDocument->createElementNS($namespace, $prefix ? $prefix . ':' . $localName : $localName);
    }

    /**
     * @return list<\DOMElement>
     */
    private function childElements(\DOMElement $parent, ?string $localName = null): array
    {
        $elements = [];
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement && ($localName === null || $child->localName === $localName)) {
                $elements[] = $child;
            }
        }
        return $elements;
    }
}

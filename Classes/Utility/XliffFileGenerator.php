<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Utility;

/**
 * Writes a new XLIFF 1.2 file in the TYPO3 Core layout: tab indentation, <header/>, and the
 * <file> attributes in the order source-language, target-language, datatype, original, date,
 * product-name. Whitespace is written as text nodes (no formatOutput), so label texts with line
 * breaks are never re-indented.
 */
final class XliffFileGenerator
{
    private const NAMESPACE_URI = 'urn:oasis:names:tc:xliff:document:1.2';

    /**
     * @param array<string|int, array{source?: string, target?: string}> $units
     * @param string $fileName English source file name (e.g. locallang.xlf) for the "original" attribute
     */
    public function generate(string $extensionKey, string $targetLanguage, array $units, bool $includeTarget, string $fileName = ''): string
    {
        $document = new \DOMDocument('1.0', 'UTF-8');

        $xliff = $document->createElement('xliff');
        $xliff->setAttribute('version', '1.2');
        $xliff->setAttribute('xmlns', self::NAMESPACE_URI);
        $document->appendChild($xliff);

        $file = $document->createElement('file');
        $file->setAttribute('source-language', 'en');
        if ($includeTarget && $targetLanguage !== '' && $targetLanguage !== 'default') {
            $file->setAttribute('target-language', $targetLanguage);
        }
        $file->setAttribute('datatype', 'plaintext');
        $file->setAttribute('original', 'EXT:' . $extensionKey . '/' . XliffUtility::LANGUAGE_DIR . ($fileName !== '' ? '/' . $fileName : ''));
        $file->setAttribute('date', (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'));
        $file->setAttribute('product-name', $extensionKey);
        $this->appendIndented($xliff, $file, 1);
        $this->appendIndented($file, $document->createElement('header'), 2);

        $body = $document->createElement('body');
        $this->appendIndented($file, $body, 2);
        foreach ($units as $key => $unit) {
            $transUnit = $document->createElement('trans-unit');
            $transUnit->setAttribute('id', (string)$key);
            $transUnit->setAttribute('resname', (string)$key);
            $this->appendIndented($body, $transUnit, 3);
            $this->appendIndented($transUnit, $this->textElement($document, 'source', (string)($unit['source'] ?? '')), 4);
            if ($includeTarget) {
                $this->appendIndented($transUnit, $this->textElement($document, 'target', (string)($unit['target'] ?? '')), 4);
            }
            $this->closeIndented($transUnit, 3);
        }
        $this->closeIndented($body, 2);
        $this->closeIndented($file, 1);
        $this->closeIndented($xliff, 0);

        // libxml writes namespace declarations before attributes; TYPO3 Core files start with version.
        return str_replace(
            '<xliff xmlns="' . self::NAMESPACE_URI . '" version="1.2">',
            '<xliff version="1.2" xmlns="' . self::NAMESPACE_URI . '">',
            (string)$document->saveXML()
        );
    }

    /**
     * A raw text node: saveXML() escapes it exactly once.
     */
    private function textElement(\DOMDocument $document, string $name, string $text): \DOMElement
    {
        $element = $document->createElement($name);
        $element->appendChild($document->createTextNode($text));
        return $element;
    }

    private function appendIndented(\DOMElement $parent, \DOMElement $child, int $depth): void
    {
        $this->closeIndented($parent, $depth);
        $parent->appendChild($child);
    }

    private function closeIndented(\DOMElement $parent, int $depth): void
    {
        $parent->appendChild($parent->ownerDocument->createTextNode("\n" . str_repeat("\t", $depth)));
    }
}

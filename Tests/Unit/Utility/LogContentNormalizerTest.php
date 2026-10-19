<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Tests\Unit\Utility;

use NITSAN\NsT3AL\Utility\LogContentNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class LogContentNormalizerTest extends UnitTestCase
{
    #[Test]
    public function currentFormatYieldsTargetThenSourceSegmentsAsPlainText(): void
    {
        $content = json_encode([
            'changed' => [
                'source' => ['del' => 'Old <b>source</b>', 'ins' => 'New source'],
                'target' => ['del' => 'Alt', 'ins' => 'Neu & "besser"'],
            ],
            'added' => ['source' => ['ins' => 'Added'], 'target' => ['ins' => '']],
            'removed' => ['source' => ['del' => 'Gone'], 'target' => ['del' => 'Weg']],
        ]);

        self::assertSame(
            [
                'changed' => [
                    ['type' => 'del', 'text' => 'Alt'],
                    ['type' => 'ins', 'text' => 'Neu & "besser"'],
                    ['type' => 'del', 'text' => 'Old <b>source</b>'],
                    ['type' => 'ins', 'text' => 'New source'],
                ],
                'added' => [
                    ['type' => 'ins', 'text' => ''],
                    ['type' => 'ins', 'text' => 'Added'],
                ],
                'removed' => [
                    ['type' => 'del', 'text' => 'Weg'],
                    ['type' => 'del', 'text' => 'Gone'],
                ],
            ],
            LogContentNormalizer::normalize((string)$content)
        );
    }

    #[Test]
    public function legacyMarkupIsDecodedToPlainText(): void
    {
        $content = json_encode([
            'key' => [
                'source' => '<ins>Save &amp; close</ins>',
                'target' => '<del>&quot;Alt&quot; &lt;x&gt;</del><ins>It&#039;s new</ins>',
            ],
        ]);

        self::assertSame(
            [
                'key' => [
                    ['type' => 'del', 'text' => '"Alt" <x>'],
                    ['type' => 'ins', 'text' => "It's new"],
                    ['type' => 'ins', 'text' => 'Save & close'],
                ],
            ],
            LogContentNormalizer::normalize((string)$content)
        );
    }

    #[Test]
    public function numericTransUnitIdsStayStringKeys(): void
    {
        $content = (string)json_encode(['0' => ['target' => ['ins' => 'a']]], JSON_FORCE_OBJECT);

        self::assertSame(['0' => [['type' => 'ins', 'text' => 'a']]], LogContentNormalizer::normalize($content));
    }

    public static function unusableContentProvider(): \Generator
    {
        yield 'empty' => [''];
        yield 'whitespace' => ['  '];
        yield 'invalid JSON' => ['{"a":'];
        yield 'scalar JSON' => ['"text"'];
        yield 'entry without parts' => ['{"a": "x", "b": {"target": "plain text without markup"}}'];
    }

    #[Test]
    #[DataProvider('unusableContentProvider')]
    public function unusableContentYieldsNoEntries(string $content): void
    {
        self::assertSame([], LogContentNormalizer::normalize($content));
    }
}

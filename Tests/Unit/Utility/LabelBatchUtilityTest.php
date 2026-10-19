<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Tests\Unit\Utility;

use NITSAN\NsT3AL\Utility\LabelBatchUtility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class LabelBatchUtilityTest extends UnitTestCase
{
    #[Test]
    public function labelsAreEncodedAsAJsonObjectEvenWithNumericKeys(): void
    {
        self::assertSame('{"0":"Zero","save":"Save & „close“"}', LabelBatchUtility::encodeLabels([0 => 'Zero', 'save' => 'Save & „close“']));
    }

    #[Test]
    public function schemaRequiresExactlyTheRequestedKeys(): void
    {
        $schema = LabelBatchUtility::buildSchema([0 => 'Zero', 'save' => 'Save']);

        self::assertSame(['0', 'save'], $schema['required']);
        self::assertFalse($schema['additionalProperties']);
        self::assertEquals((object)['0' => ['type' => 'string'], 'save' => ['type' => 'string']], $schema['properties']);
    }

    #[Test]
    public function positionalResultsAreMappedBackToTheLabelKeys(): void
    {
        self::assertSame(
            ['save' => 'Speichern', 'close' => ''],
            LabelBatchUtility::combineWithKeys(['save' => 'Save', 'close' => 'Close'], ['Speichern', null])
        );
        self::assertNull(LabelBatchUtility::combineWithKeys(['save' => 'Save', 'close' => 'Close'], ['Speichern']));
    }

    public static function decodableResponses(): array
    {
        return [
            'plain object' => ['{"save":"Speichern"}'],
            'surrounding whitespace' => ["  {\"save\":\"Speichern\"}\n"],
            'json fence' => ["```json\n{\"save\":\"Speichern\"}\n```"],
            'bare fence' => ["```\n{\"save\":\"Speichern\"}\n```"],
        ];
    }

    #[Test]
    #[DataProvider('decodableResponses')]
    public function jsonObjectResponsesAreDecoded(string $response): void
    {
        self::assertSame(['save' => 'Speichern'], LabelBatchUtility::decodeLabels($response));
    }

    public static function undecodableResponses(): array
    {
        return [
            'empty' => [''],
            'list' => ['["Speichern"]'],
            'prose' => ['Here is the translation: {"save":"Speichern"}'],
            'broken json' => ['{"save":"Speichern"'],
        ];
    }

    #[Test]
    #[DataProvider('undecodableResponses')]
    public function nonObjectResponsesAreRejected(string $response): void
    {
        self::assertNull(LabelBatchUtility::decodeLabels($response));
    }

    #[Test]
    public function instructionsNameTheTargetLanguage(): void
    {
        $instructions = LabelBatchUtility::buildInstructions('German', 'de');

        self::assertStringContainsString('to German (ISO code: de)', $instructions);
        self::assertStringContainsString('exactly the same keys', $instructions);
    }
}

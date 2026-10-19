<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Tests\Unit\Backend\View;

use NITSAN\NsT3AL\Backend\View\ConcurrentEditNotice;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ConcurrentEditNoticeTest extends UnitTestCase
{
    private const NOW = 1_800_000_000;

    private const LANGUAGE_DIR = __DIR__ . '/../../../../Resources/Private/Language/';

    /**
     * @return array<string, array{int, string}>
     */
    public static function elapsedSeconds(): array
    {
        return [
            'just now' => [0, 'heli started editing the file locallang.xlf less than a minute ago.'],
            'within 30 seconds' => [29, 'heli started editing the file locallang.xlf less than a minute ago.'],
            'almost a minute' => [59, 'heli started editing the file locallang.xlf less than a minute ago.'],
            'one minute' => [60, 'heli started editing the file locallang.xlf 1 minute ago.'],
            'almost two minutes' => [119, 'heli started editing the file locallang.xlf 1 minute ago.'],
            'two minutes' => [120, 'heli started editing the file locallang.xlf 2 minutes ago.'],
            'end of the lock lifetime' => [599, 'heli started editing the file locallang.xlf 9 minutes ago.'],
        ];
    }

    #[Test]
    #[DataProvider('elapsedSeconds')]
    public function namesTheUserTheFileAndTheElapsedTime(int $seconds, string $expected): void
    {
        $lock = ['time' => self::NOW - $seconds, 'username' => 'heli'];

        self::assertSame($expected, ConcurrentEditNotice::message($lock, 'locallang.xlf', self::NOW, $this->translator('locallang_mod.xlf')));
    }

    #[Test]
    public function lockWithoutUsernameNamesSomeoneElse(): void
    {
        $lock = ['time' => self::NOW - 180, 'username' => ''];

        self::assertSame(
            'Someone else started editing the file locallang_db.xlf 3 minutes ago.',
            ConcurrentEditNotice::message($lock, 'locallang_db.xlf', self::NOW, $this->translator('locallang_mod.xlf'))
        );
    }

    #[Test]
    public function lockTimeInTheFutureCountsAsJustStarted(): void
    {
        $lock = ['time' => self::NOW + 5, 'username' => 'heli'];

        self::assertSame(
            'heli started editing the file locallang.xlf less than a minute ago.',
            ConcurrentEditNotice::message($lock, 'locallang.xlf', self::NOW, $this->translator('locallang_mod.xlf'))
        );
    }

    #[Test]
    public function germanMessagesUseTheSameArguments(): void
    {
        $translate = $this->translator('de.locallang_mod.xlf');

        self::assertSame(
            'heli hat vor weniger als einer Minute begonnen, die Datei locallang.xlf zu bearbeiten.',
            ConcurrentEditNotice::message(['time' => self::NOW - 10, 'username' => 'heli'], 'locallang.xlf', self::NOW, $translate)
        );
        self::assertSame(
            'heli hat vor 1 Minute begonnen, die Datei locallang.xlf zu bearbeiten.',
            ConcurrentEditNotice::message(['time' => self::NOW - 75, 'username' => 'heli'], 'locallang.xlf', self::NOW, $translate)
        );
        self::assertSame(
            'Jemand anderes hat vor 4 Minuten begonnen, die Datei locallang.xlf zu bearbeiten.',
            ConcurrentEditNotice::message(['time' => self::NOW - 240], 'locallang.xlf', self::NOW, $translate)
        );
    }

    /**
     * @return \Closure(string): string
     */
    private function translator(string $labelFile): \Closure
    {
        $xml = simplexml_load_file(self::LANGUAGE_DIR . $labelFile);
        self::assertNotFalse($xml);
        $labels = [];
        foreach ($xml->file->body->{'trans-unit'} as $unit) {
            $labels[(string)$unit['id']] = (string)($unit->target ?? $unit->source);
        }
        return static function (string $key) use ($labels, $labelFile): string {
            self::assertArrayHasKey($key, $labels, $labelFile . ' has no label ' . $key);
            return $labels[$key];
        };
    }
}

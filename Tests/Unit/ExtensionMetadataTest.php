<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * TYPO3 v14 takes the extension title from the composer.json description up to the first " - "
 * (Breaking #108304); ext_emconf.php must carry the same title / description for classic installs.
 */
final class ExtensionMetadataTest extends UnitTestCase
{
    private const EXTENSION_PATH = __DIR__ . '/../../';

    #[Test]
    public function composerDescriptionSplitsIntoTheExtEmconfTitleAndDescription(): void
    {
        $description = (string)($this->composerManifest()['description'] ?? '');
        $parts = explode(' - ', $description, 2);
        $emConf = $this->extEmconf();

        self::assertCount(2, $parts, 'composer.json description needs the "<Title> - <description>" format');
        self::assertSame($emConf['title'], $parts[0]);
        self::assertSame($emConf['description'], $parts[1]);
    }

    #[Test]
    public function descriptionsContainNoUrls(): void
    {
        self::assertStringNotContainsString('://', (string)($this->composerManifest()['description'] ?? ''));
        self::assertStringNotContainsString('://', (string)($this->extEmconf()['description'] ?? ''));
    }

    #[Test]
    public function homepageAndSupportUseOneDomain(): void
    {
        $manifest = $this->composerManifest();
        $hosts = array_unique([
            parse_url((string)($manifest['homepage'] ?? ''), PHP_URL_HOST),
            parse_url((string)($manifest['support']['issues'] ?? ''), PHP_URL_HOST),
        ]);

        self::assertCount(1, $hosts);
    }

    /**
     * @return array<string, mixed>
     */
    private function composerManifest(): array
    {
        return json_decode((string)file_get_contents(self::EXTENSION_PATH . 'composer.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function extEmconf(): array
    {
        /** @var array<string, array<string, mixed>> $EM_CONF filled by the include below */
        $EM_CONF = [];
        $_EXTKEY = 'ns_t3al';
        include self::EXTENSION_PATH . 'ext_emconf.php';
        return $EM_CONF[$_EXTKEY];
    }
}

<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Tests\Unit\Domain\Repository;

use NITSAN\NsT3AL\Domain\Repository\LogsRepository;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class LogsRepositoryRetentionTest extends UnitTestCase
{
    #[Test]
    public function defaultRetentionMatchesTheExtensionConfigurationDefault(): void
    {
        $template = (string)file_get_contents(__DIR__ . '/../../../../ext_conf_template.txt');

        self::assertSame(1, preg_match('/^logRetentionDays\s*=\s*(\d+)\s*$/m', $template, $matches));
        self::assertSame(LogsRepository::DEFAULT_RETENTION_DAYS, (int)$matches[1]);
    }
}

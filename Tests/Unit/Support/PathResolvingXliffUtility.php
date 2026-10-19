<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Tests\Unit\Support;

use NITSAN\NsT3AL\Utility\XliffUtility;

/**
 * Exposes the protected path resolution of XliffUtility for the path guard tests.
 */
final class PathResolvingXliffUtility extends XliffUtility
{
    public function resolve(string $fileName, string $language, string $packagePath): string
    {
        return $this->fetchFilePath('demo', $fileName, $language, $packagePath);
    }
}

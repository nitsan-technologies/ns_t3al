<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Tests\Unit\Support;

/**
 * Real directory below the system temp dir; symlink and realpath() checks need a real filesystem.
 */
trait TemporaryDirectoryTrait
{
    private string $temporaryDirectory = '';

    private function createTemporaryDirectory(): string
    {
        $path = sys_get_temp_dir() . '/ns_t3al_test_' . bin2hex(random_bytes(6));
        mkdir($path, 0777, true);
        $this->temporaryDirectory = (string)realpath($path);
        return $this->temporaryDirectory;
    }

    private function removeTemporaryDirectory(): void
    {
        if ($this->temporaryDirectory === '' || !is_dir($this->temporaryDirectory)) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->temporaryDirectory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($entries as $entry) {
            $path = $entry->getPathname();
            if (is_link($path) || !is_dir($path)) {
                unlink($path);
            } else {
                rmdir($path);
            }
        }
        rmdir($this->temporaryDirectory);
        $this->temporaryDirectory = '';
    }

    private function writeFile(string $path, string $content = ''): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
    }
}

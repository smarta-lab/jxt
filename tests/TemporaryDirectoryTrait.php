<?php

declare(strict_types=1);

namespace SmartA\Jxt\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Provides tests with a temporary directory below `tests/tmp/` that is removed after the test.
 */
trait TemporaryDirectoryTrait
{
    private string $temporaryDirectory;

    /**
     * Creates a new, empty temporary directory.
     */
    private function createTemporaryDirectory(): string
    {
        $this->temporaryDirectory = __DIR__ . '/tmp/' . uniqid('test_', true);
        mkdir($this->temporaryDirectory, 0777, true);

        return $this->temporaryDirectory;
    }

    /**
     * Removes the temporary directory including its content.
     */
    private function removeTemporaryDirectory(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->temporaryDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->temporaryDirectory);
    }

    /**
     * Writes a file relative to the temporary directory and creates missing directories.
     */
    private function writeFile(string $relativePath, string $content = ''): void
    {
        $path = $this->temporaryDirectory . '/' . $relativePath;

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $content);
    }
}

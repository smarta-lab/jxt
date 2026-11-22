<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Build;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SmartA\Jxt\PhpErrors;
use SplFileInfo;
use ZipArchive;

/**
 * Temporary directory for a build, e.g. for the files of a Git tag or the ZIP files of the extensions of a package.
 */
final readonly class TemporaryDirectory
{
    /**
     * Extracts the archive into the directory.
     *
     * @throws BuildException if the archive cannot be read or extracted
     */
    public static function extract(string $archive, string $directory): void
    {
        $zip = new ZipArchive();

        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            throw new BuildException(sprintf('Cannot open archive %s.', $archive));
        }

        $extracted = $zip->extractTo($directory);
        $zip->close();

        if (!$extracted) {
            throw new BuildException(sprintf('Cannot extract archive %s to %s.', $archive, $directory));
        }
    }

    /**
     * Creates a new directory below the system's temporary directory that only the user can read; the caller removes
     * it with remove().
     *
     * @throws BuildException if the directory cannot be created
     */
    public static function create(): string
    {
        $directory = sprintf('%s/jxt-%s', sys_get_temp_dir(), bin2hex(random_bytes(8)));
        [$created, $error] = PhpErrors::capture(static fn (): bool => mkdir($directory, 0700));

        return $created ? $directory : throw new BuildException(
            sprintf('Cannot create temporary directory %s', $directory) . ($error === null ? '.' : ': ' . $error),
        );
    }

    /**
     * Removes the directory including its content.
     */
    public static function remove(string $directory): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directory);
    }
}

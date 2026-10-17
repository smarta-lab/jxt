<?php

declare(strict_types=1);

namespace SmartA\Jxt\Build;

use Closure;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SmartA\Jxt\Git\GitRepository;
use SmartA\Jxt\PhpErrors;
use SplFileInfo;
use ZipArchive;

/**
 * Exports the files of a Git tag into a temporary directory, so that a build does not contain uncommitted
 * changes.
 */
final readonly class TagExporter
{
    /**
     * Exports the tag, passes the directory with its files to the callback and removes it afterwards.
     *
     * The directory corresponds to the directory of the repository, which may be a subdirectory of the
     * work tree.
     *
     * @template T
     *
     * @param Closure(string): T $callback
     *
     * @return T
     *
     * @throws BuildException if the temporary directory cannot be created or the archive not extracted
     * @throws \SmartA\Jxt\Git\GitException if the tag does not exist or Git fails
     */
    public function export(GitRepository $git, string $tag, Closure $callback): mixed
    {
        $directory = $this->createTemporaryDirectory();

        try {
            $archive = $directory . '/tag.zip';
            $files = $directory . '/files';
            $git->archive($tag, $archive);
            $this->extract($archive, $files);

            return $callback($files);
        } finally {
            $this->remove($directory);
        }
    }

    /**
     * Creates a new directory below the system's temporary directory that only the user can read.
     *
     * @throws BuildException if the directory cannot be created
     */
    private function createTemporaryDirectory(): string
    {
        $directory = sprintf('%s/jxt-%s', sys_get_temp_dir(), bin2hex(random_bytes(8)));
        [$created, $error] = PhpErrors::capture(static fn (): bool => mkdir($directory, 0700));

        return $created ? $directory : throw new BuildException(
            sprintf('Cannot create temporary directory %s', $directory) . ($error === null ? '.' : ': ' . $error),
        );
    }

    /**
     * Extracts the archive into the directory.
     *
     * @throws BuildException if the archive cannot be read or extracted
     */
    private function extract(string $archive, string $directory): void
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
     * Removes the directory including its content.
     */
    private function remove(string $directory): void
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

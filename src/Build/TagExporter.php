<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Build;

use Closure;
use SmartA\Jxt\Git\GitRepository;

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
        $directory = TemporaryDirectory::create();

        try {
            $archive = $directory . '/tag.zip';
            $files = $directory . '/files';
            $git->archive($tag, $archive);
            TemporaryDirectory::extract($archive, $files);

            return $callback($files);
        } finally {
            TemporaryDirectory::remove($directory);
        }
    }
}

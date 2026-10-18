<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Plugin\Console\Jxt\Plan;

/**
 * Changes that jxt planned for the test site: deletions, directories and files of one extension.
 */
final readonly class Plan
{
    /**
     * @param int             $directoryMode Mode for the extension's directories and for created directories
     * @param int             $fileMode      Mode for the extension's files
     * @param list<Deletion>  $deletions     Entries to delete, directory contents before the directory
     * @param list<string>    $directories   Directories of the extension, parents first
     * @param list<PlanFile>  $files         All files of the extension
     */
    public function __construct(
        public int $directoryMode,
        public int $fileMode,
        public array $deletions,
        public array $directories,
        public array $files,
        public ?ExtensionIdentity $extension,
    ) {
    }

    /**
     * Returns the number of files that are copied.
     */
    public function copyCount(): int
    {
        return count(array_filter($this->files, static fn (PlanFile $file): bool => $file->source !== null));
    }
}

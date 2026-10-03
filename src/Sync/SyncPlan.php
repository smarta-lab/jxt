<?php

declare(strict_types=1);

namespace SmartA\Jxt\Sync;

/**
 * Result of comparing project and server: what `jxt sync` copies, deletes and reports.
 */
final readonly class SyncPlan
{
    /**
     * @param list<SyncFile> $files                 All files of the extension with their status
     * @param list<Deletion> $deletions             Entries to delete, directory contents before the directory
     * @param list<string>   $directories           Directories of the extension, parents first
     * @param list<string>   $changedInstallerFiles New or changed install scripts and SQL files
     */
    public function __construct(
        public array $files,
        public array $deletions,
        public array $directories,
        public ManifestComparison $comparison,
        public array $changedInstallerFiles,
    ) {
    }

    /**
     * Returns the new and changed files.
     *
     * @return list<SyncFile>
     */
    public function changedFiles(): array
    {
        return array_values(array_filter(
            $this->files,
            static fn (SyncFile $file): bool => $file->status !== FileStatus::Unchanged,
        ));
    }

    /**
     * Returns the number of unchanged files.
     */
    public function unchangedCount(): int
    {
        return count($this->files) - count($this->changedFiles());
    }
}

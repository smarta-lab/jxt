<?php

declare(strict_types=1);

namespace SmartA\Jxt\Deployment;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Expands the directory entries of a deployment plan into individual files.
 *
 * Files and directories whose name starts with `.git` (e.g. `.gitkeep`, `.gitignore`) are skipped.
 */
final class PlanExpander
{
    /**
     * Returns a file entry with its target for each source file, sorted alphabetically per directory.
     *
     * @return list<PlanEntry>
     *
     * @throws DeploymentException if a file or directory listed in the plan is missing
     */
    public function expand(DeploymentPlan $plan, string $projectDirectory): array
    {
        $files = [];

        foreach ($plan->entries as $entry) {
            $files = [...$files, ...$this->expandEntry($entry, $projectDirectory)];
        }

        return $files;
    }

    /**
     * Expands a single entry.
     *
     * @return list<PlanEntry>
     */
    private function expandEntry(PlanEntry $entry, string $projectDirectory): array
    {
        $path = $projectDirectory . '/' . $entry->source;

        return match ($entry->kind) {
            EntryKind::File => is_file($path)
                ? [$entry]
                : throw new DeploymentException(sprintf('Datei %s aus dem Manifest fehlt.', $entry->source)),
            EntryKind::Directory => is_dir($path)
                ? $this->directoryEntries($entry, $path)
                : throw new DeploymentException(sprintf('Ordner %s aus dem Manifest fehlt.', $entry->source)),
        };
    }

    /**
     * Returns the file entries for all files below a directory.
     *
     * @return list<PlanEntry>
     */
    private function directoryEntries(PlanEntry $entry, string $path): array
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            static fn (SplFileInfo $file): bool => !str_starts_with($file->getFilename(), '.git'),
        ));
        $relativePaths = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relativePaths[] = substr($file->getPathname(), strlen($path) + 1);
            }
        }

        sort($relativePaths, SORT_STRING);

        return array_map(
            static fn (string $relativePath): PlanEntry => new PlanEntry(
                $entry->source . '/' . $relativePath,
                $entry->target . '/' . $relativePath,
                EntryKind::File,
            ),
            $relativePaths,
        );
    }
}

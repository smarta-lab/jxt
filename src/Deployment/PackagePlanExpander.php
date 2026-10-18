<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Deployment;

use ZipArchive;

/**
 * Expands the directory entries of a deployment plan into the files of a ZIP file, i.e. into the files that the
 * Joomla installer installs from it.
 *
 * Unlike `PlanExpander`, no files are skipped, since the Joomla installer copies everything in a listed folder.
 */
final class PackagePlanExpander
{
    /**
     * Returns a file entry with its target for each file of the plan in the ZIP file, sorted alphabetically per
     * directory; entries missing from the ZIP file are skipped.
     *
     * @return list<PlanEntry>
     *
     * @throws DeploymentException if the ZIP file cannot be opened
     */
    public function expand(DeploymentPlan $plan, string $zipPath): array
    {
        $names = $this->fileNames($zipPath);
        $files = [];

        foreach ($plan->entries as $entry) {
            $files = [...$files, ...match ($entry->kind) {
                EntryKind::File => in_array($entry->source, $names, true) ? [$entry] : [],
                EntryKind::Directory => $this->directoryEntries($entry, $names),
            }];
        }

        return $files;
    }

    /**
     * Returns the file entries for all files of the ZIP file below the directory of the entry.
     *
     * @param list<string> $names Sorted file names in the ZIP file
     *
     * @return list<PlanEntry>
     */
    private function directoryEntries(PlanEntry $entry, array $names): array
    {
        $prefix = $entry->source . '/';

        return array_values(array_map(
            static fn (string $name): PlanEntry => new PlanEntry(
                $name,
                $entry->target . '/' . substr($name, strlen($prefix)),
                EntryKind::File,
            ),
            array_filter($names, static fn (string $name): bool => str_starts_with($name, $prefix)),
        ));
    }

    /**
     * Returns the names of the files (not directories) in the ZIP file, sorted.
     *
     * @return list<string>
     *
     * @throws DeploymentException if the ZIP file cannot be opened
     */
    private function fileNames(string $zipPath): array
    {
        $zip = new ZipArchive();

        if (!is_file($zipPath) || $zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new DeploymentException(sprintf('Cannot open ZIP file %s.', $zipPath));
        }

        $names = [];

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);

                if (!str_ends_with($name, '/')) {
                    $names[] = $name;
                }
            }
        } finally {
            $zip->close();
        }

        sort($names, SORT_STRING);

        return $names;
    }
}

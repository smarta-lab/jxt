<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Sync;

use SmartA\Jxt\Deployment\EntryKind;
use SmartA\Jxt\Deployment\PlanEntry;
use SmartA\Jxt\Manifest\Manifest;

/**
 * Finds entries on the server in the exclusive directories of an extension that do not belong to its plan.
 *
 * Used by `jxt sync` to delete them and by `jxt install` to report those that the Joomla installer keeps.
 */
final class LeftoverFinder
{
    /**
     * Returns the entries in the exclusive directories of the old and the new manifest that the new plan does not contain.
     *
     * Directory contents come before the directory; symbolic links are treated like files and not followed.
     *
     * @param Manifest        $new   Manifest in the extension directory
     * @param Manifest        $old   Manifest installed on the server
     * @param list<PlanEntry> $files Expanded files of the new plan
     *
     * @return list<Deletion>
     */
    public function find(Manifest $new, Manifest $old, array $files, string $joomlaRoot): array
    {
        $targets = array_fill_keys(array_map(static fn (PlanEntry $file): string => $file->target, $files), true);
        $neededDirectories = array_fill_keys($this->managedDirectories($new, $files), true);
        $leftovers = [];

        foreach (array_unique([...$new->plan->exclusiveDirectories, ...$old->plan->exclusiveDirectories]) as $exclusiveDirectory) {
            if (!is_dir($joomlaRoot . '/' . $exclusiveDirectory)) {
                continue;
            }

            $leftovers = [
                ...$leftovers,
                ...$this->staleEntries($joomlaRoot, $exclusiveDirectory, $targets, $neededDirectories),
            ];

            if (!isset($neededDirectories[$exclusiveDirectory])) {
                $leftovers[] = new Deletion($exclusiveDirectory, EntryKind::Directory);
            }
        }

        return $leftovers;
    }

    /**
     * Drops the entries inside leftover directories, so that each directory is listed only once, and sorts the rest.
     *
     * @param list<Deletion> $leftovers
     *
     * @return list<Deletion>
     */
    public function topLevel(array $leftovers): array
    {
        $directories = array_map(
            static fn (Deletion $leftover): string => $leftover->target . '/',
            array_filter($leftovers, static fn (Deletion $leftover): bool => $leftover->kind === EntryKind::Directory),
        );

        $topLevel = array_filter($leftovers, static function (Deletion $leftover) use ($directories): bool {
            foreach ($directories as $directory) {
                if (str_starts_with($leftover->target, $directory)) {
                    return false;
                }
            }

            return true;
        });

        usort($topLevel, static fn (Deletion $a, Deletion $b): int => strcmp($a->target, $b->target));

        return $topLevel;
    }

    /**
     * Returns all directories inside the exclusive directories that must exist for the plan, sorted.
     *
     * @param list<PlanEntry> $files Expanded files of the plan
     *
     * @return list<string>
     */
    public function managedDirectories(Manifest $manifest, array $files): array
    {
        $directories = array_fill_keys($manifest->plan->exclusiveDirectories, true);

        foreach ($files as $file) {
            $exclusiveDirectory = self::exclusiveDirectoryOf($file->target, $manifest->plan->exclusiveDirectories);

            if ($exclusiveDirectory === null) {
                continue;
            }

            for ($directory = dirname($file->target); $directory !== $exclusiveDirectory; $directory = dirname($directory)) {
                $directories[$directory] = true;
            }
        }

        $directories = array_keys($directories);
        sort($directories, SORT_STRING);

        return $directories;
    }

    /**
     * Returns the exclusive directory containing the path, or null.
     *
     * @param list<string> $exclusiveDirectories
     */
    public static function exclusiveDirectoryOf(string $path, array $exclusiveDirectories): ?string
    {
        foreach ($exclusiveDirectories as $directory) {
            if (str_starts_with($path, $directory . '/')) {
                return $directory;
            }
        }

        return null;
    }

    /**
     * Recursively returns all entries of a server directory that do not belong to the plan.
     *
     * @param array<string, true> $targets
     * @param array<string, true> $neededDirectories
     *
     * @return list<Deletion>
     */
    private function staleEntries(string $joomlaRoot, string $directory, array $targets, array $neededDirectories): array
    {
        $leftovers = [];

        foreach (scandir($joomlaRoot . '/' . $directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $directory . '/' . $name;
            $absolutePath = $joomlaRoot . '/' . $path;

            if (is_dir($absolutePath) && !is_link($absolutePath)) {
                $leftovers = [...$leftovers, ...$this->staleEntries($joomlaRoot, $path, $targets, $neededDirectories)];

                if (!isset($neededDirectories[$path])) {
                    $leftovers[] = new Deletion($path, EntryKind::Directory);
                }
            } elseif (!isset($targets[$path])) {
                $leftovers[] = new Deletion($path, EntryKind::File);
            }
        }

        return $leftovers;
    }
}

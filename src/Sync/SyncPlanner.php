<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Sync;

use SmartA\Jxt\Deployment\EntryKind;
use SmartA\Jxt\Deployment\PlanEntry;
use SmartA\Jxt\Deployment\PlanExpander;
use SmartA\Jxt\Manifest\Manifest;

/**
 * Compares the project with the server and determines what `jxt sync` has to copy and delete.
 *
 * The manifest installed on the server serves as the server's state:
 * - In exclusive directories (install directory, `media/<destination>`) of the old and the new
 *   manifest, the actual content is compared; anything not belonging to the new plan is deleted.
 * - In shared directories (e.g. `language/de-DE/`), only files that were listed in the old
 *   manifest and are missing from the new one are deleted.
 */
final readonly class SyncPlanner
{
    public const string AUTOLOAD_CACHE = 'administrator/cache/autoload_psr4.php';

    public function __construct(
        private PlanExpander $planExpander = new PlanExpander(),
        private LeftoverFinder $leftoverFinder = new LeftoverFinder(),
    ) {
    }

    /**
     * Creates the sync plan.
     *
     * @param Manifest $new              Manifest in the extension directory
     * @param Manifest $old              Manifest installed on the server
     *
     * @throws \SmartA\Jxt\Deployment\DeploymentException if files listed in the manifest are missing
     */
    public function plan(Manifest $new, Manifest $old, string $extensionDirectory, string $joomlaRoot): SyncPlan
    {
        $files = $this->planExpander->expand($new->plan, $extensionDirectory);
        $targets = array_fill_keys(array_map(static fn (PlanEntry $file): string => $file->target, $files), true);
        $directories = $this->leftoverFinder->managedDirectories($new, $files);
        $comparison = $this->compare($old, $new);
        $syncFiles = array_map(
            fn (PlanEntry $file): SyncFile => new SyncFile($file, $this->status($file, $extensionDirectory, $joomlaRoot)),
            $files,
        );

        $deletions = [
            ...$this->leftoverFinder->find($new, $old, $files, $joomlaRoot),
            ...$this->sharedDeletions($old, $joomlaRoot, $targets),
        ];

        if ($comparison->namespaceChanged && file_exists($joomlaRoot . '/' . self::AUTOLOAD_CACHE)) {
            $deletions[] = new Deletion(self::AUTOLOAD_CACHE, EntryKind::File);
        }

        return new SyncPlan(
            $syncFiles,
            $deletions,
            $directories,
            $comparison,
            $this->changedInstallerFiles($new, $syncFiles),
        );
    }

    /**
     * Compares version, namespace and installer sections of both manifests.
     */
    private function compare(Manifest $old, Manifest $new): ManifestComparison
    {
        $sectionNames = array_unique([...array_keys($old->installer->sections), ...array_keys($new->installer->sections)]);
        $changedSections = array_filter(
            $sectionNames,
            static fn (string $name): bool => ($old->installer->sections[$name] ?? null) !== ($new->installer->sections[$name] ?? null),
        );

        return new ManifestComparison(
            $old->version,
            $new->version,
            $old->installer->namespace !== $new->installer->namespace
                || $old->installer->namespacePath !== $new->installer->namespacePath,
            array_values($changedSections),
        );
    }

    /**
     * Compares a project file with the file on the server.
     */
    private function status(PlanEntry $file, string $extensionDirectory, string $joomlaRoot): FileStatus
    {
        $source = $extensionDirectory . '/' . $file->source;
        $target = $joomlaRoot . '/' . $file->target;

        return match (true) {
            !is_file($target) => FileStatus::New,
            filesize($source) !== filesize($target), hash_file('xxh128', $source) !== hash_file('xxh128', $target)
                => FileStatus::Changed,
            default => FileStatus::Unchanged,
        };
    }

    /**
     * Determines files of the old manifest outside the exclusive directories that are missing from the new plan.
     *
     * @param array<string, true> $targets
     *
     * @return list<Deletion>
     */
    private function sharedDeletions(Manifest $old, string $joomlaRoot, array $targets): array
    {
        $deletions = [];

        foreach ($old->plan->entries as $entry) {
            if (
                $entry->kind === EntryKind::File
                && LeftoverFinder::exclusiveDirectoryOf($entry->target, $old->plan->exclusiveDirectories) === null
                && !isset($targets[$entry->target])
                && is_file($joomlaRoot . '/' . $entry->target)
            ) {
                $deletions[] = new Deletion($entry->target, EntryKind::File);
            }
        }

        return $deletions;
    }

    /**
     * Returns the new or changed files that only the Joomla installer evaluates.
     *
     * @param list<SyncFile> $files
     *
     * @return list<string>
     */
    private function changedInstallerFiles(Manifest $new, array $files): array
    {
        $changed = [];

        foreach ($files as $file) {
            $target = $file->entry->target;

            if ($file->status !== FileStatus::Unchanged && $this->isBelowAny($target, $new->installer->targets)) {
                $changed[] = $target;
            }
        }

        return $changed;
    }

    /**
     * Checks whether the path equals one of the given paths or lies below it.
     *
     * @param list<string> $paths
     */
    private function isBelowAny(string $path, array $paths): bool
    {
        foreach ($paths as $candidate) {
            if ($path === $candidate || str_starts_with($path, $candidate . '/')) {
                return true;
            }
        }

        return false;
    }
}

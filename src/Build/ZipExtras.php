<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Build;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SmartA\Jxt\Deployment\PlanEntry;
use SmartA\Jxt\Deployment\PlanExpander;
use SmartA\Jxt\Manifest\Manifest;
use SmartA\Jxt\Manifest\ManifestLocator;
use SmartA\Jxt\Manifest\PackageEntry;
use SplFileInfo;

/**
 * Collects the files of the setting `zip_extras`, which `jxt build` adds to the ZIP file without the manifest
 * listing them, e.g. the license: the Joomla installer only copies what the manifest lists, so they are shipped but
 * not installed.
 *
 * Each file keeps its path relative to the project directory in the ZIP file; directories are expanded, skipping
 * names starting with `.git` as the PlanExpander does.
 */
final readonly class ZipExtras
{
    public function __construct(
        private PlanExpander $planExpander = new PlanExpander(),
        private ManifestLocator $manifestLocator = new ManifestLocator(),
    ) {
    }

    /**
     * Returns the files by their path in the ZIP file, with their absolute source path.
     *
     * @param list<string> $paths              Normalized paths of `zip_extras`, relative to the project directory
     * @param Manifest     $manifest           Manifest of the ZIP file
     * @param string       $extensionDirectory Directory of the manifest, the root of the ZIP file
     *
     * @return array<string, string>
     *
     * @throws BuildException if a path does not exist, a file is already part of the ZIP file through the manifest
     *                        or is a Joomla manifest
     * @throws \SmartA\Jxt\Deployment\DeploymentException if files listed in the manifest are missing
     */
    public function collect(array $paths, string $projectDirectory, Manifest $manifest, string $extensionDirectory): array
    {
        if ($paths === []) {
            return [];
        }

        $files = [];

        foreach ($paths as $path) {
            $files += $this->files($path, $projectDirectory);
        }

        $listed = array_map(static fn (PlanEntry $file): string => $file->source, $this->planExpander->expand($manifest->plan, $extensionDirectory));

        foreach ($files as $name => $source) {
            $this->checkFile((string) $name, $source, $listed, $manifest);
        }

        return $files;
    }

    /**
     * Returns the file, or the files below the directory, by their path relative to the project directory.
     *
     * @return array<string, string>
     *
     * @throws BuildException if the path does not exist
     */
    private function files(string $path, string $projectDirectory): array
    {
        $absolutePath = $projectDirectory . '/' . $path;

        if (is_file($absolutePath)) {
            return [$path => $absolutePath];
        }

        if (!is_dir($absolutePath)) {
            throw new BuildException(sprintf('%s of zip_extras does not exist in %s.', $path, $projectDirectory));
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($absolutePath, FilesystemIterator::SKIP_DOTS),
            static fn (SplFileInfo $file): bool => !str_starts_with($file->getFilename(), '.git'),
        ));
        $files = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[$path . '/' . substr($file->getPathname(), strlen($absolutePath) + 1)] = $file->getPathname();
            }
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * Checks that the file is neither part of the ZIP file through the manifest (or, for a package, an entry of its
     * `<files>`) nor a Joomla manifest, which the Joomla installer looks for in the ZIP file and its subdirectories.
     *
     * @param list<string> $listed Paths of the files of the plan in the ZIP file
     *
     * @throws BuildException otherwise
     */
    private function checkFile(string $name, string $source, array $listed, Manifest $manifest): void
    {
        $entries = array_map(static fn (PackageEntry $entry): string => $entry->source, $manifest->packageEntries);
        $inEntry = array_any($entries, static fn (string $entry): bool => $name === $entry || str_starts_with($name, $entry . '/'));

        if (in_array($name, $listed, true) || $inEntry) {
            throw new BuildException(sprintf('%s of zip_extras is already part of the ZIP file through the manifest.', $name));
        }

        if (str_ends_with(strtolower($name), '.xml') && $this->manifestLocator->isManifest($source)) {
            throw new BuildException(sprintf(
                '%s of zip_extras is a Joomla manifest, which the Joomla installer could take for the one of the extension.',
                $name,
            ));
        }
    }
}

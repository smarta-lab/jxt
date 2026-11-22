<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Manifest;

/**
 * Finds the manifests of the extensions of a package in the subdirectories of the package directory.
 *
 * Searches up to three levels below the package directory, skipping hidden directories and directories that hold
 * build results, dependencies or tests (`dist`, `vendor`, `node_modules`, `tests`) as well as the output directory.
 * Like the ManifestLocator, only XML files with the root element `<extension>` count.
 */
final readonly class ChildManifestFinder
{
    private const int MAXIMUM_DEPTH = 3;
    private const array SKIPPED_DIRECTORIES = ['dist', 'vendor', 'node_modules', 'tests'];

    public function __construct(
        private ManifestLocator $manifestLocator = new ManifestLocator(),
        private ManifestParser $manifestParser = new ManifestParser(),
    ) {
    }

    /**
     * Returns the manifests found and those that could not be parsed.
     *
     * @param list<string> $excludedDirectories Absolute paths of further directories to skip, e.g. the output directory
     */
    public function find(string $packageDirectory, array $excludedDirectories = []): ChildManifestSearch
    {
        $manifests = [];
        $invalid = [];

        foreach ($this->candidates($packageDirectory, '', 1, $this->normalized($excludedDirectories)) as $relativePath) {
            $file = $packageDirectory . '/' . $relativePath;

            try {
                $manifests[] = new ChildManifest($file, $relativePath, $this->manifestParser->parseFile($file));
            } catch (ManifestException $exception) {
                $invalid[$relativePath] = ($exception->getPrevious() ?? $exception)->getMessage();
            }
        }

        return new ChildManifestSearch($manifests, $invalid);
    }

    /**
     * Returns the paths of the manifests in the subdirectories of the directory, relative to the package directory.
     *
     * @param list<string> $excluded Normalized absolute paths of directories to skip
     *
     * @return list<string>
     */
    private function candidates(string $packageDirectory, string $prefix, int $depth, array $excluded): array
    {
        $directory = rtrim($packageDirectory . '/' . $prefix, '/');
        $candidates = [];

        foreach ($this->subdirectories($directory, $excluded) as $name) {
            $relativeDirectory = $prefix . $name;
            $manifests = array_filter(
                glob($packageDirectory . '/' . $relativeDirectory . '/*.xml') ?: [],
                $this->manifestLocator->isManifest(...),
            );
            sort($manifests, SORT_STRING);

            foreach ($manifests as $manifest) {
                $candidates[] = $relativeDirectory . '/' . basename($manifest);
            }

            if ($depth < self::MAXIMUM_DEPTH) {
                $candidates = [...$candidates, ...$this->candidates($packageDirectory, $relativeDirectory . '/', $depth + 1, $excluded)];
            }
        }

        return $candidates;
    }

    /**
     * Returns the names of the subdirectories to search, sorted.
     *
     * @param list<string> $excluded Normalized absolute paths of directories to skip
     *
     * @return list<string>
     */
    private function subdirectories(string $directory, array $excluded): array
    {
        $names = array_values(array_filter(
            scandir($directory) ?: [],
            static fn (string $name): bool => !str_starts_with($name, '.')
                && !in_array($name, self::SKIPPED_DIRECTORIES, true)
                && is_dir($directory . '/' . $name)
                && !in_array(realpath($directory . '/' . $name), $excluded, true),
        ));
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * Resolves the excluded directories that exist, so that they can be compared with the subdirectories.
     *
     * @param list<string> $directories
     *
     * @return list<string>
     */
    private function normalized(array $directories): array
    {
        return array_values(array_filter(array_map(realpath(...), $directories), is_string(...)));
    }
}

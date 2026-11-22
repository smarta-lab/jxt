<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Build;

use Closure;
use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SmartA\Jxt\Deployment\UnlistedSourceFinder;
use SmartA\Jxt\JxtException;
use SmartA\Jxt\Manifest\ChildManifest;
use SmartA\Jxt\Manifest\Manifest;
use SmartA\Jxt\Manifest\PackageEntry;
use SmartA\Jxt\Manifest\PackageResolver;
use SplFileInfo;

/**
 * Builds the ZIP file of a package: the extensions listed in its `<files>` from the subdirectories and the files of
 * the package itself.
 *
 * Each entry is assigned to the manifest in the subdirectories that matches its attributes (PackageResolver); that
 * extension is built from its own directory into a temporary directory, as a ZIP
 * file for an entry ending in `.zip`, otherwise as unpacked files. An entry without a manifest is taken as it is if
 * the file or directory exists. Only the package ZIP goes to the output directory.
 */
final readonly class PackageAssembler
{
    public function __construct(
        private PackageResolver $packageResolver = new PackageResolver(),
        private PackageBuilder $packageBuilder = new PackageBuilder(),
        private UnlistedSourceFinder $unlistedSourceFinder = new UnlistedSourceFinder(),
    ) {
    }

    /**
     * Builds the extensions and the package ZIP.
     *
     * @param Closure(string, string): ProjectBuild $buildChild Builds the project in the directory (first argument)
     *                                                          into the output directory (second argument)
     *
     * @throws JxtException if an entry matches no manifest and no file, or several manifests, or if building an
     *                      extension or the package fails
     */
    public function build(Manifest $manifest, string $packageDirectory, string $distDirectory, Closure $buildChild): ProjectBuild
    {
        if ($manifest->packageEntries === []) {
            throw new BuildException('The package manifest lists no extensions in <files>.');
        }

        $resolution = $this->packageResolver->resolve($manifest, $packageDirectory, [$distDirectory]);
        $errors = $resolution->errors();

        if ($errors !== []) {
            throw new BuildException($errors[0]);
        }

        $temporaryDirectory = TemporaryDirectory::create();

        try {
            $files = [];
            $children = [];
            $warnings = [];
            $unlisted = $this->unlistedSourceFinder->find($manifest->plan, $packageDirectory);

            foreach ($resolution->entries as $index => $resolved) {
                $entry = $resolved->entry;

                if ($resolved->child === null) {
                    $files = [...$files, ...$this->existingFiles($entry->source, (string) $resolved->existingPath)];

                    continue;
                }

                $child = $this->buildChild($entry, $resolved->child, $temporaryDirectory . '/' . $index, $buildChild);
                $files = [...$files, ...$this->childFiles($entry, $child, $temporaryDirectory . '/' . $index . '-files')];
                $children[] = $child;
                $warnings = [...$warnings, ...array_filter([$entry->nameWarning($child->manifest)])];
                $unlisted = [...$unlisted, ...$this->childUnlisted($resolved->child, $packageDirectory, $child)];
            }

            $package = $this->packageBuilder->build($manifest, $packageDirectory, $distDirectory, $files);
        } finally {
            TemporaryDirectory::remove($temporaryDirectory);
        }

        return new ProjectBuild($manifest, $package, $unlisted, null, $children, $warnings);
    }

    /**
     * Builds the extension from its directory and checks that the build used the manifest that matches the entry.
     *
     * @param Closure(string, string): ProjectBuild $buildChild
     *
     * @throws JxtException if the build fails or used another manifest, e.g. one set in the `.jxt.json` of the
     *                      extension
     */
    private function buildChild(PackageEntry $entry, ChildManifest $source, string $distDirectory, Closure $buildChild): ProjectBuild
    {
        $build = $buildChild($source->directory(), $distDirectory);

        if (!$entry->matches($build->manifest)) {
            throw new BuildException(sprintf(
                'Building %s used the manifest of %s, which does not match %s (%s).',
                dirname($source->relativePath),
                $build->manifest->name(),
                $entry->source,
                $entry->identity(),
            ));
        }

        return $build;
    }

    /**
     * Returns the files for the entry: the ZIP file of the extension, or its extracted files below the entry.
     *
     * @return array<string, string> Absolute source paths by their path in the package ZIP
     *
     * @throws BuildException if the ZIP file cannot be extracted
     */
    private function childFiles(PackageEntry $entry, ProjectBuild $child, string $extractDirectory): array
    {
        if ($entry->isZip()) {
            return [$entry->source => $child->package->path];
        }

        TemporaryDirectory::extract($child->package->path, $extractDirectory);

        return $this->existingFiles($entry->source, $extractDirectory);
    }

    /**
     * Returns the file, or all files below the directory, by their path in the package ZIP.
     *
     * Like the PlanExpander, names starting with `.git` are skipped.
     *
     * @return array<string, string>
     */
    private function existingFiles(string $name, string $path): array
    {
        if (is_file($path)) {
            return [$name => $path];
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            static fn (SplFileInfo $file): bool => !str_starts_with($file->getFilename(), '.git'),
        ));
        $files = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[$name . '/' . substr($file->getPathname(), strlen($path) + 1)] = $file->getPathname();
            }
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * Returns the unlisted files of the extension relative to the package directory.
     *
     * @return list<string>
     */
    private function childUnlisted(ChildManifest $source, string $packageDirectory, ProjectBuild $child): array
    {
        $prefix = substr($source->directory(), strlen($packageDirectory) + 1) . '/';

        return array_map(static fn (string $path): string => $prefix . $path, $child->unlisted);
    }
}

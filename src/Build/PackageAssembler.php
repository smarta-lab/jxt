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
use SmartA\Jxt\Manifest\ChildManifestFinder;
use SmartA\Jxt\Manifest\ChildManifestSearch;
use SmartA\Jxt\Manifest\Manifest;
use SmartA\Jxt\Manifest\PackageEntry;
use SplFileInfo;

/**
 * Builds the ZIP file of a package: the extensions listed in its `<files>` from the subdirectories and the files of
 * the package itself.
 *
 * Each entry is assigned to the manifest in the subdirectories that matches its attributes (ChildManifestFinder,
 * PackageEntry::matches()); that extension is built from its own directory into a temporary directory, as a ZIP
 * file for an entry ending in `.zip`, otherwise as unpacked files. An entry without a manifest is taken as it is if
 * the file or directory exists. Only the package ZIP goes to the output directory.
 */
final readonly class PackageAssembler
{
    public function __construct(
        private ChildManifestFinder $childManifestFinder = new ChildManifestFinder(),
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

        $search = $this->childManifestFinder->find($packageDirectory, [$distDirectory]);
        $sources = array_map(
            fn (PackageEntry $entry): ChildManifest|string => $this->source($entry, $search, $packageDirectory),
            $manifest->packageEntries,
        );
        $temporaryDirectory = TemporaryDirectory::create();

        try {
            $files = [];
            $children = [];
            $warnings = [];
            $unlisted = $this->unlistedSourceFinder->find($manifest->plan, $packageDirectory);

            foreach ($manifest->packageEntries as $index => $entry) {
                $source = $sources[$index];

                if (is_string($source)) {
                    $files = [...$files, ...$this->existingFiles($entry->source, $source)];

                    continue;
                }

                $child = $this->buildChild($entry, $source, $temporaryDirectory . '/' . $index, $buildChild);
                $files = [...$files, ...$this->childFiles($entry, $child, $temporaryDirectory . '/' . $index . '-files')];
                $children[] = $child;
                $warnings = [...$warnings, ...$this->nameWarnings($entry, $child)];
                $unlisted = [...$unlisted, ...$this->childUnlisted($source, $packageDirectory, $child)];
            }

            $package = $this->packageBuilder->build($manifest, $packageDirectory, $distDirectory, $files);
        } finally {
            TemporaryDirectory::remove($temporaryDirectory);
        }

        return new ProjectBuild($manifest, $package, $unlisted, null, $children, $warnings);
    }

    /**
     * Returns the manifest that matches the entry, or else the absolute path of the existing file or directory.
     *
     * @throws BuildException if several manifests match, a manifest differs only in client or group, or there is
     *                        neither a manifest nor a file
     */
    private function source(PackageEntry $entry, ChildManifestSearch $search, string $packageDirectory): ChildManifest|string
    {
        $matching = array_values(array_filter($search->manifests, static fn (ChildManifest $child): bool => $entry->matches($child->manifest)));

        if (count($matching) > 1) {
            throw new BuildException(sprintf(
                'Several manifests match %s (%s): %s.',
                $entry->source,
                $entry->identity(),
                implode(', ', array_map(static fn (ChildManifest $child): string => $child->relativePath, $matching)),
            ));
        }

        if ($matching !== []) {
            return $matching[0];
        }

        foreach ($search->manifests as $child) {
            if ($entry->sharesId($child->manifest)) {
                throw new BuildException(sprintf(
                    '%s (%s) does not match %s (%s); Joomla would not find the extension when uninstalling the package.',
                    $entry->source,
                    $entry->identity(),
                    $child->relativePath,
                    $this->clientOrGroup($child->manifest),
                ));
            }
        }

        $path = $packageDirectory . '/' . $entry->source;

        return file_exists($path) ? $path : throw new BuildException(
            sprintf('No manifest in the subdirectories matches %s (%s), and the file does not exist.', $entry->source, $entry->identity())
            . $this->invalidManifests($search),
        );
    }

    /**
     * Describes the attribute that distinguishes extensions of the same type and element, e.g. `group="system"`.
     */
    private function clientOrGroup(Manifest $manifest): string
    {
        return $manifest->group === null
            ? sprintf('client="%s"', $manifest->client->value)
            : sprintf('group="%s"', $manifest->group);
    }

    /**
     * Lists the manifests that could not be parsed, since one of them may be meant for the entry.
     */
    private function invalidManifests(ChildManifestSearch $search): string
    {
        if ($search->invalid === []) {
            return '';
        }

        return "\nManifests that could not be read:" . implode('', array_map(
            static fn (string $path, string $message): string => "\n" . $path . ': ' . $message,
            array_keys($search->invalid),
            $search->invalid,
        ));
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
     * Warns if a ZIP entry is named neither like the ZIP file of the extension nor without a version, which hints at
     * an outdated version in the name.
     *
     * @return list<string>
     */
    private function nameWarnings(PackageEntry $entry, ProjectBuild $child): array
    {
        $expected = basename($child->package->path);
        $actual = basename($entry->source);

        if (!$entry->isZip() || $actual === $expected || $actual === $child->manifest->name() . '.zip') {
            return [];
        }

        return [sprintf(
            '%s contains %s, whose ZIP file is named %s.',
            $entry->source,
            trim($child->manifest->name() . ' ' . ($child->manifest->version ?? '')),
            $expected,
        )];
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

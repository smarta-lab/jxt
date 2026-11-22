<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Install;

use SmartA\Jxt\Build\TemporaryDirectory;
use SmartA\Jxt\Deployment\PackagePlanExpander;
use SmartA\Jxt\Deployment\PlanExpander;
use SmartA\Jxt\JxtException;
use SmartA\Jxt\Manifest\ExtensionType;
use SmartA\Jxt\Manifest\Manifest;
use SmartA\Jxt\Manifest\ManifestLocator;
use SmartA\Jxt\Manifest\ManifestParser;
use SmartA\Jxt\Manifest\PackageManifestReader;

/**
 * Reads the extensions of a ZIP file and the files that the Joomla installer installs from it, for the leftover
 * check of `jxt install`.
 *
 * For a package these are its extensions, from the ZIP files or directories listed in `<files>`; the files of the
 * package itself are not included. Entries that jxt cannot read (missing, of an unsupported type, or a package in a
 * package) are skipped with a reason.
 */
final readonly class ZipContentReader
{
    public function __construct(
        private PackageManifestReader $packageManifestReader = new PackageManifestReader(),
        private PackagePlanExpander $packagePlanExpander = new PackagePlanExpander(),
        private ManifestLocator $manifestLocator = new ManifestLocator(),
        private ManifestParser $manifestParser = new ManifestParser(),
        private PlanExpander $planExpander = new PlanExpander(),
    ) {
    }

    /**
     * Returns the extensions of the ZIP file.
     *
     * @param Manifest $manifest Manifest of the ZIP file
     *
     * @throws JxtException if the ZIP file cannot be read or, for a package, extracted
     */
    public function read(Manifest $manifest, string $zipPath): ZipContent
    {
        if ($manifest->type !== ExtensionType::Package) {
            return new ZipContent([new ZipExtension($manifest, $this->packagePlanExpander->expand($manifest->plan, $zipPath))]);
        }

        $directory = TemporaryDirectory::create();

        try {
            TemporaryDirectory::extract($zipPath, $directory . '/package');
            $extensions = [];
            $skipped = [];

            foreach ($manifest->packageEntries as $entry) {
                try {
                    $extensions[] = $this->entry($directory . '/package/' . $entry->source);
                } catch (JxtException $exception) {
                    $skipped[$entry->source] = ($exception->getPrevious() ?? $exception)->getMessage();
                }
            }

            return new ZipContent($extensions, $skipped);
        } finally {
            TemporaryDirectory::remove($directory);
        }
    }

    /**
     * Reads the extension of an entry from its extracted ZIP file or directory.
     *
     * @throws JxtException if the entry is missing, cannot be read or is a package itself
     */
    private function entry(string $path): ZipExtension
    {
        if (is_file($path)) {
            $manifest = $this->withoutPackage($this->packageManifestReader->read($path));

            return new ZipExtension($manifest, $this->packagePlanExpander->expand($manifest->plan, $path));
        }

        if (is_dir($path)) {
            $manifest = $this->withoutPackage($this->manifestParser->parseFile($this->manifestLocator->locate($path)));

            return new ZipExtension($manifest, $this->planExpander->expand($manifest->plan, $path));
        }

        throw new InstallException('missing in the package');
    }

    /**
     * Returns the manifest unless it is a package manifest, whose extensions jxt does not read again.
     *
     * @throws InstallException for a package manifest
     */
    private function withoutPackage(Manifest $manifest): Manifest
    {
        return $manifest->type === ExtensionType::Package ? throw new InstallException('a package in a package') : $manifest;
    }
}

<?php

declare(strict_types=1);

namespace SmartA\Jxt\Build;

use SmartA\Jxt\Deployment\PlanExpander;
use SmartA\Jxt\Manifest\ExtensionType;
use SmartA\Jxt\Manifest\Manifest;
use ZipArchive;

/**
 * Creates a ZIP file that can be installed in the Joomla backend.
 *
 * The ZIP contains the manifest and all files listed in it with their paths in the project directory,
 * as expected by the Joomla installer.
 */
final readonly class PackageBuilder
{
    public function __construct(private PlanExpander $planExpander = new PlanExpander())
    {
    }

    /**
     * Creates the ZIP file in the output directory; an existing file with the same name is replaced.
     *
     * @throws BuildException if the output directory or the ZIP file cannot be created
     * @throws \SmartA\Jxt\Deployment\DeploymentException if files listed in the manifest are missing
     */
    public function build(Manifest $manifest, string $projectDirectory, string $distDirectory): Package
    {
        $files = $this->planExpander->expand($manifest->plan, $projectDirectory);
        $path = $distDirectory . '/' . $this->fileName($manifest);

        if (!is_dir($distDirectory) && !mkdir($distDirectory, 0777, true) && !is_dir($distDirectory)) {
            throw new BuildException(sprintf('Zielordner %s kann nicht angelegt werden.', $distDirectory));
        }

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new BuildException(sprintf('ZIP-Datei %s kann nicht angelegt werden.', $path));
        }

        foreach ($files as $file) {
            $zip->addFile($projectDirectory . '/' . $file->source, $file->source);
        }

        if (!$zip->close()) {
            throw new BuildException(sprintf('ZIP-Datei %s kann nicht geschrieben werden.', $path));
        }

        return new Package($path, count($files));
    }

    /**
     * Returns the file name, e.g. `mod_example-1.0.0.zip` or `tpl_cassiopeia-1.0.0.zip`.
     */
    private function fileName(Manifest $manifest): string
    {
        $name = match ($manifest->type) {
            ExtensionType::Module => $manifest->element,
            ExtensionType::Template => 'tpl_' . $manifest->element,
        };
        $version = $manifest->version === null
            ? ''
            : '-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $manifest->version);

        return $name . $version . '.zip';
    }
}

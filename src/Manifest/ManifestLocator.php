<?php

declare(strict_types=1);

namespace SmartA\Jxt\Manifest;

/**
 * Locates the manifest of an extension in the project directory.
 *
 * Any XML file in the directory itself (not in subdirectories) whose root element is
 * `<extension>` counts as a manifest. Other XML files such as `phpunit.xml` are ignored.
 */
final class ManifestLocator
{
    /**
     * Returns the path of the only manifest in the given directory.
     *
     * @throws ManifestException if no manifest or more than one is found
     */
    public function locate(string $directory): string
    {
        $manifests = array_values(array_filter(
            glob(rtrim($directory, '/') . '/*.xml') ?: [],
            $this->isManifest(...),
        ));

        return match (count($manifests)) {
            0 => throw new ManifestException(sprintf('Kein Joomla-Manifest in %s gefunden.', $directory)),
            1 => $manifests[0],
            default => throw new ManifestException(sprintf(
                'Mehrere Joomla-Manifeste in %s gefunden: %s',
                $directory,
                implode(', ', array_map(basename(...), $manifests)),
            )),
        };
    }

    /**
     * Checks whether the file contains well-formed XML with the root element `<extension>`.
     */
    private function isManifest(string $file): bool
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $root = simplexml_load_file($file);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $root !== false && $root->getName() === 'extension';
    }
}

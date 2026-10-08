<?php

declare(strict_types=1);

namespace SmartA\Jxt\Manifest;

use SimpleXMLElement;
use ZipArchive;

/**
 * Reads the manifest of an existing ZIP file, e.g. of an older version that `jxt install --zip` installs.
 *
 * As in the project directory, any XML file in the root directory of the ZIP (not in subdirectories) whose
 * root element is `<extension>` counts as a manifest.
 */
final readonly class PackageManifestReader
{
    public function __construct(private ManifestParser $manifestParser = new ManifestParser())
    {
    }

    /**
     * Returns the only manifest in the root directory of the ZIP file.
     *
     * @throws ManifestException if the ZIP file cannot be opened, contains no manifest or more than one,
     *                           or the manifest is invalid
     */
    public function read(string $path): Manifest
    {
        $manifests = $this->manifests($path);

        if (count($manifests) !== 1) {
            throw new ManifestException($manifests === []
                ? sprintf('No Joomla manifest found in the root directory of %s.', $path)
                : sprintf('Several Joomla manifests found in %s: %s.', $path, implode(', ', array_keys($manifests))));
        }

        try {
            return $this->manifestParser->parse(reset($manifests), (string) array_key_first($manifests));
        } catch (ManifestException $exception) {
            throw new ManifestException($path . ': ' . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * Returns the content of the XML files in the root directory with the root element `<extension>`.
     *
     * @return array<string, string> Content by file name, sorted by file name
     *
     * @throws ManifestException if the ZIP file cannot be opened
     */
    private function manifests(string $path): array
    {
        $zip = new ZipArchive();

        if (!is_file($path) || $zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ManifestException(sprintf('Cannot open ZIP file %s.', $path));
        }

        $manifests = [];

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);
                $content = str_contains($name, '/') || !str_ends_with($name, '.xml') ? false : $zip->getFromIndex($index);

                if ($content !== false && $this->isManifest($content)) {
                    $manifests[$name] = $content;
                }
            }
        } finally {
            $zip->close();
        }

        ksort($manifests);

        return $manifests;
    }

    /**
     * Checks whether the content is well-formed XML with the root element `<extension>`.
     */
    private function isManifest(string $xml): bool
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $root = simplexml_load_string($xml);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $root instanceof SimpleXMLElement && $root->getName() === 'extension';
    }
}

<?php

declare(strict_types=1);

namespace SmartA\Jxt\Manifest;

use SmartA\Jxt\Configuration\ConfigurationLoader;

/**
 * Locates the manifest of an extension.
 *
 * Without a configured manifest, any XML file in the project directory itself (not in subdirectories)
 * whose root element is `<extension>` counts as a manifest. Other XML files such as `phpunit.xml` are
 * ignored.
 */
final class ManifestLocator
{
    private const string CONFIGURATION_HINT = 'Set the manifest with jxt config set manifest <path>, '
        . 'e.g. if the extension is in a subdirectory.';

    /**
     * Returns the path of the configured manifest or of the only manifest in the project directory.
     *
     * @param string|null $manifestFile Absolute path of the manifest configured in `.jxt.json`, if any
     *
     * @throws ManifestException if the configured file is missing or no manifest, or if no manifest or
     *                           more than one is found in the project directory
     */
    public function locate(string $directory, ?string $manifestFile = null): string
    {
        return $manifestFile === null ? $this->search($directory) : $this->check($manifestFile);
    }

    /**
     * Checks that the configured file exists and is a manifest.
     *
     * @throws ManifestException if the file is missing or no manifest
     */
    private function check(string $manifestFile): string
    {
        if (!is_file($manifestFile)) {
            throw new ManifestException(sprintf(
                'Manifest %s not found. Please check the manifest setting in %s.',
                $manifestFile,
                ConfigurationLoader::PROJECT_FILE,
            ));
        }

        if (!$this->isManifest($manifestFile)) {
            throw new ManifestException(sprintf(
                '%s is not a Joomla manifest (root element <extension> expected).',
                $manifestFile,
            ));
        }

        return $manifestFile;
    }

    /**
     * Returns the only manifest in the project directory.
     *
     * @throws ManifestException if no manifest or more than one is found
     */
    private function search(string $directory): string
    {
        $manifests = array_values(array_filter(
            glob(rtrim($directory, '/') . '/*.xml') ?: [],
            $this->isManifest(...),
        ));

        return match (count($manifests)) {
            0 => throw new ManifestException(
                sprintf('No Joomla manifest found in %s. %s', $directory, self::CONFIGURATION_HINT),
            ),
            1 => $manifests[0],
            default => throw new ManifestException(sprintf(
                'Several Joomla manifests found in %s: %s. %s',
                $directory,
                implode(', ', array_map(basename(...), $manifests)),
                self::CONFIGURATION_HINT,
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

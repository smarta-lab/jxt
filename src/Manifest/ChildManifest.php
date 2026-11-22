<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Manifest;

/**
 * Manifest of an extension found in a subdirectory of a package.
 */
final readonly class ChildManifest
{
    /**
     * @param string   $file         Absolute path of the manifest
     * @param string   $relativePath Path of the manifest relative to the package directory
     * @param Manifest $manifest     The parsed manifest
     */
    public function __construct(
        public string $file,
        public string $relativePath,
        public Manifest $manifest,
    ) {
    }

    /**
     * Returns the absolute path of the directory of the manifest, the project directory of the extension.
     */
    public function directory(): string
    {
        return dirname($this->file);
    }
}

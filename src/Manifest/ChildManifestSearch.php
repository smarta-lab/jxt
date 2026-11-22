<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Manifest;

/**
 * Result of the search for the manifests of the extensions of a package.
 */
final readonly class ChildManifestSearch
{
    /**
     * @param list<ChildManifest>   $manifests Manifests found, sorted by path
     * @param array<string, string> $invalid   Error message per manifest that could not be parsed, by its path
     *                                         relative to the package directory
     */
    public function __construct(
        public array $manifests,
        public array $invalid,
    ) {
    }
}

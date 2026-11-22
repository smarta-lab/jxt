<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Manifest;

/**
 * Entry of a package manifest with what it refers to: the manifest of an extension in a subdirectory, an existing
 * file or directory, or an error.
 */
final readonly class ResolvedEntry
{
    /**
     * @param ChildManifest|null $child        Manifest that matches the entry
     * @param string|null        $existingPath Absolute path of the file or directory taken as it is, if no manifest
     *                                         matches
     * @param string|null        $error        Why the entry cannot be resolved, null if it can
     */
    public function __construct(
        public PackageEntry $entry,
        public ?ChildManifest $child = null,
        public ?string $existingPath = null,
        public ?string $error = null,
    ) {
    }
}

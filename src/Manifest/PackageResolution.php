<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Manifest;

/**
 * Result of assigning the entries of a package manifest to the extensions in its subdirectories.
 */
final readonly class PackageResolution
{
    /**
     * @param list<ResolvedEntry> $entries    The entries in the order of the manifest
     * @param list<ChildManifest> $unassigned Manifests found in the subdirectories that no entry refers to
     */
    public function __construct(
        public array $entries,
        public array $unassigned,
    ) {
    }

    /**
     * Returns the manifests that entries refer to, in the order of the entries.
     *
     * @return list<ChildManifest>
     */
    public function children(): array
    {
        return array_values(array_filter(array_map(static fn (ResolvedEntry $entry): ?ChildManifest => $entry->child, $this->entries)));
    }

    /**
     * Returns the errors of the entries.
     *
     * @return list<string>
     */
    public function errors(): array
    {
        return array_values(array_filter(array_map(static fn (ResolvedEntry $entry): ?string => $entry->error, $this->entries)));
    }
}

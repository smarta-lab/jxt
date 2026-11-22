<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Manifest;

/**
 * Assigns the entries of a package manifest to the manifests in the subdirectories of the package.
 *
 * An entry refers to the only manifest that matches its attributes (PackageEntry::matches()); without one, to the
 * file or directory if it exists. It is an error if several manifests match, if a manifest differs only in client
 * or group (Joomla would not find the extension when uninstalling the package), or if there is neither a manifest
 * nor a file.
 */
final readonly class PackageResolver
{
    public function __construct(private ChildManifestFinder $childManifestFinder = new ChildManifestFinder())
    {
    }

    /**
     * Resolves all entries of the package manifest.
     *
     * @param list<string> $excludedDirectories Absolute paths of directories not to search, e.g. the output directory
     */
    public function resolve(Manifest $package, string $packageDirectory, array $excludedDirectories = []): PackageResolution
    {
        $search = $this->childManifestFinder->find($packageDirectory, $excludedDirectories);
        $entries = array_map(
            fn (PackageEntry $entry): ResolvedEntry => $this->resolveEntry($entry, $search, $packageDirectory),
            $package->packageEntries,
        );
        $assigned = array_map(static fn (ResolvedEntry $entry): ?ChildManifest => $entry->child, $entries);
        $unassigned = array_values(array_filter(
            $search->manifests,
            static fn (ChildManifest $child): bool => !in_array($child, $assigned, true)
                && !array_any($entries, static fn (ResolvedEntry $entry): bool => $entry->entry->sharesId($child->manifest)),
        ));

        return new PackageResolution($entries, $unassigned);
    }

    /**
     * Resolves one entry.
     */
    private function resolveEntry(PackageEntry $entry, ChildManifestSearch $search, string $packageDirectory): ResolvedEntry
    {
        $matching = array_values(array_filter($search->manifests, static fn (ChildManifest $child): bool => $entry->matches($child->manifest)));

        if (count($matching) > 1) {
            return new ResolvedEntry($entry, error: sprintf(
                'Several manifests match %s (%s): %s.',
                $entry->source,
                $entry->identity(),
                implode(', ', array_map(static fn (ChildManifest $child): string => $child->relativePath, $matching)),
            ));
        }

        if ($matching !== []) {
            return new ResolvedEntry($entry, $matching[0]);
        }

        foreach ($search->manifests as $child) {
            if ($entry->sharesId($child->manifest)) {
                return new ResolvedEntry($entry, error: sprintf(
                    '%s (%s) does not match %s (%s); Joomla would not find the extension when uninstalling the package.',
                    $entry->source,
                    $entry->identity(),
                    $child->relativePath,
                    $child->manifest->group === null
                        ? sprintf('client="%s"', $child->manifest->client->value)
                        : sprintf('group="%s"', $child->manifest->group),
                ));
            }
        }

        $path = $packageDirectory . '/' . $entry->source;

        return file_exists($path) ? new ResolvedEntry($entry, existingPath: $path) : new ResolvedEntry($entry, error: sprintf(
            'No manifest in the subdirectories matches %s (%s), and the file does not exist.',
            $entry->source,
            $entry->identity(),
        ) . $this->invalidManifests($search));
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
}

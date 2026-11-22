<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Manifest;

/**
 * Entry in `<files>` of a package manifest: an extension of the package as a ZIP file or directory.
 *
 * The attributes `type`, `id`, `client` and `group` are what the Joomla installer uses to find the extension when
 * uninstalling the package; the type is kept as a string, since Joomla also accepts types that jxt does not support.
 */
final readonly class PackageEntry
{
    /**
     * @param string      $source Path relative to the package directory, including the `folder` of `<files>`
     * @param string|null $type   Attribute `type`, null if missing
     * @param string|null $id     Attribute `id` (the element of the extension), null if missing
     * @param string|null $client Attribute `client`, null if missing
     * @param string|null $group  Attribute `group`, null if missing
     */
    public function __construct(
        public string $source,
        public ?string $type,
        public ?string $id,
        public ?string $client,
        public ?string $group,
    ) {
    }

    /**
     * Checks whether the entry identifies the extension of the manifest the way Joomla does when uninstalling:
     * by type and element, for plugins also by group, for modules and templates also by client.
     */
    public function matches(Manifest $manifest): bool
    {
        if (!$this->sharesId($manifest)) {
            return false;
        }

        return match ($manifest->type) {
            ExtensionType::Plugin => $this->group === $manifest->group,
            ExtensionType::Module, ExtensionType::Template => $this->client === $manifest->client->value,
            default => true,
        };
    }

    /**
     * Checks whether type and id of the entry name the extension of the manifest, regardless of client and group.
     */
    public function sharesId(Manifest $manifest): bool
    {
        return $this->type === $manifest->type->value && $this->id === $manifest->element;
    }

    /**
     * Checks whether the entry names a ZIP file, which jxt builds for it; other entries get the unpacked files.
     */
    public function isZip(): bool
    {
        return strtolower(pathinfo($this->source, PATHINFO_EXTENSION)) === 'zip';
    }

    /**
     * Returns a warning if the entry is a ZIP file named neither like the ZIP file of the extension nor without a
     * version, which hints at an outdated version in the name; null otherwise.
     */
    public function nameWarning(Manifest $extension): ?string
    {
        $expected = $extension->zipFileName();
        $actual = basename($this->source);

        if (!$this->isZip() || $actual === $expected || $actual === $extension->name() . '.zip') {
            return null;
        }

        return sprintf(
            '%s contains %s, whose ZIP file is named %s.',
            $this->source,
            trim($extension->name() . ' ' . ($extension->version ?? '')),
            $expected,
        );
    }

    /**
     * Describes the identifying attributes for messages, e.g. `type="plugin" id="example" group="system"`.
     */
    public function identity(): string
    {
        $attributes = array_filter(
            ['type' => $this->type, 'id' => $this->id, 'client' => $this->client, 'group' => $this->group],
            static fn (?string $value): bool => $value !== null,
        );

        if ($attributes === []) {
            return 'no type and id';
        }

        return implode(' ', array_map(
            static fn (string $name, string $value): string => sprintf('%s="%s"', $name, $value),
            array_keys($attributes),
            $attributes,
        ));
    }
}

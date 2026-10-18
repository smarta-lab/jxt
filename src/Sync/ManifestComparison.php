<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Sync;

/**
 * Differences between the installed and the new manifest that `jxt sync` cannot reproduce itself.
 */
final readonly class ManifestComparison
{
    /**
     * @param list<string> $changedInstallerSections Names of the changed sections, e.g. `updateservers`
     */
    public function __construct(
        public ?string $oldVersion,
        public ?string $newVersion,
        public bool $namespaceChanged,
        public array $changedInstallerSections,
    ) {
    }

    /**
     * Whether `<version>` has changed.
     */
    public function versionChanged(): bool
    {
        return $this->oldVersion !== $this->newVersion;
    }
}

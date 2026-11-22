<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Build;

use SmartA\Jxt\Git\VersionTag;
use SmartA\Jxt\Manifest\Manifest;

/**
 * Result of building the ZIP file of a project, from the working copy or from a Git tag.
 */
final readonly class ProjectBuild
{
    /**
     * @param Manifest     $manifest Manifest the ZIP file was built from
     * @param Package      $package  The created ZIP file
     * @param list<string> $unlisted Files in the source directories that the manifest does not list
     * @param string|null  $tag      Git tag the ZIP file was built from, null for the working copy
     * @param list<ProjectBuild> $children Builds of the extensions of a package, empty for other types
     * @param list<string>       $warnings Notes on the package, e.g. a ZIP file named after another version
     */
    public function __construct(
        public Manifest $manifest,
        public Package $package,
        public array $unlisted,
        public ?string $tag = null,
        public array $children = [],
        public array $warnings = [],
    ) {
    }

    /**
     * Returns the same build, marked as built from the tag.
     */
    public function withTag(string $tag): self
    {
        return new self($this->manifest, $this->package, $this->unlisted, $tag, $this->children, $this->warnings);
    }

    /**
     * Checks whether the tag names the manifest version, with or without a `v` prefix; always true without
     * a tag.
     */
    public function tagMatchesVersion(): bool
    {
        return $this->tag === null || VersionTag::names($this->tag, $this->manifest->version);
    }
}

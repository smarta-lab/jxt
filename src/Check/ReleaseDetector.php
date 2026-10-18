<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Check;

use SmartA\Jxt\Git\GitRepository;

/**
 * Determines whether the manifest version is already released.
 *
 * With Git, a tag for the version means it is released (pre-check for the next version), otherwise it
 * is about to be released (final check). Tags are recognized with or without a `v` prefix, depending on
 * the existing tags. Without Git, a dated heading in `CHANGELOG.md` counts as released; without both,
 * it is a pre-check.
 */
final class ReleaseDetector
{
    /**
     * Returns the release state of the version.
     */
    public function detect(?string $version, ?GitRepository $git, ?Changelog $changelog): ReleaseState
    {
        if ($version === null) {
            return new ReleaseState(CheckMode::PreCheck, null, null, 'the manifest has no version');
        }

        return $git === null ? $this->fromChangelog($version, $changelog) : $this->fromTags($version, $git->tags());
    }

    /**
     * Returns the release state for checking the files of a tag: always a final check of that release, compared
     * with the highest tagged version below it.
     */
    public function detectTag(?string $version, GitRepository $git, string $tag): ReleaseState
    {
        if ($version === null) {
            return $this->detect(null, $git, null);
        }

        $tags = $git->tags();
        $prefix = match ($tag) {
            $version => '',
            'v' . $version => 'v',
            default => $this->tagPrefix($tags),
        };
        $previous = $this->previousVersion($version, $prefix, $tags);

        return new ReleaseState(
            CheckMode::FinalCheck,
            $previous === null ? null : $prefix . $previous,
            $previous,
            sprintf('files of tag %s', $tag),
        );
    }

    /**
     * Determines the state from the Git tags.
     *
     * @param list<string> $tags
     */
    private function fromTags(string $version, array $tags): ReleaseState
    {
        $prefix = $this->tagPrefix($tags);
        $tag = $prefix . $version;

        if (in_array($tag, $tags, true)) {
            return new ReleaseState(CheckMode::PreCheck, $tag, $version, sprintf('%s is already tagged', $tag));
        }

        $previous = $this->previousVersion($version, $prefix, $tags);

        return new ReleaseState(
            CheckMode::FinalCheck,
            $previous === null ? null : $prefix . $previous,
            $previous,
            sprintf('%s is not tagged yet', $tag),
        );
    }

    /**
     * Determines the state from the changelog when there is no Git repository.
     */
    private function fromChangelog(string $version, ?Changelog $changelog): ReleaseState
    {
        return match (true) {
            $changelog === null => new ReleaseState(CheckMode::PreCheck, null, null, 'no Git repository and no ' . Changelog::FILE),
            $changelog->isReleased($version) => new ReleaseState(
                CheckMode::PreCheck,
                null,
                null,
                sprintf('%s is released according to %s (no Git repository)', $version, Changelog::FILE),
            ),
            default => new ReleaseState(
                CheckMode::FinalCheck,
                null,
                null,
                sprintf('%s is not released in %s yet (no Git repository)', $version, Changelog::FILE),
            ),
        };
    }

    /**
     * Returns `v` if the version tags use it or if there are none yet, otherwise an empty prefix.
     *
     * @param list<string> $tags
     */
    private function tagPrefix(array $tags): string
    {
        $versionTags = array_filter($tags, static fn (string $tag): bool => preg_match('/^v?\d+\.\d+\.\d+/', $tag) === 1);

        return $versionTags === [] || array_filter($versionTags, static fn (string $tag): bool => $tag[0] === 'v') !== []
            ? 'v'
            : '';
    }

    /**
     * Returns the highest tagged version below the version, or null if there is none.
     *
     * @param list<string> $tags
     */
    private function previousVersion(string $version, string $prefix, array $tags): ?string
    {
        $versions = [];

        foreach ($tags as $tag) {
            $candidate = substr($tag, strlen($prefix));

            if (
                str_starts_with($tag, $prefix)
                && preg_match('/^\d+\.\d+\.\d+/', $candidate) === 1
                && version_compare($candidate, $version, '<')
            ) {
                $versions[] = $candidate;
            }
        }

        usort($versions, static fn (string $a, string $b): int => version_compare($a, $b));

        return $versions === [] ? null : $versions[count($versions) - 1];
    }
}

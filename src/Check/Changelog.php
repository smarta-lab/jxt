<?php

declare(strict_types=1);

namespace SmartA\Jxt\Check;

/**
 * Content of a `CHANGELOG.md` in the format of "Keep a Changelog".
 *
 * Releases are headings `## [X.Y.Z] - YYYY-MM-DD`, changes not released yet are list entries under
 * `## [Unreleased]`.
 */
final readonly class Changelog
{
    public const string FILE = 'CHANGELOG.md';

    public function __construct(private string $content)
    {
    }

    /**
     * Reads the changelog of the project, or returns null if there is none.
     */
    public static function load(string $projectDirectory): ?self
    {
        $path = $projectDirectory . '/' . self::FILE;
        $content = is_file($path) ? file_get_contents($path) : false;

        return $content === false ? null : new self($content);
    }

    /**
     * Checks whether the file uses the format, i.e. has an `[Unreleased]` or a version heading.
     */
    public function isKeepAChangelog(): bool
    {
        return preg_match('/^## \[(Unreleased|\d+\.\d+\.\d+[^\]]*)\]/m', $this->content) === 1;
    }

    /**
     * Returns the released versions with their date (null if the heading has none).
     *
     * @return array<string, string|null>
     */
    public function releases(): array
    {
        preg_match_all('/^## \[(\d+\.\d+\.\d+[^\]]*)\](?: - (\d{4}-\d{2}-\d{2}))?/m', $this->content, $matches, PREG_SET_ORDER);
        $releases = [];

        foreach ($matches as $match) {
            $releases[$match[1]] = ($match[2] ?? '') === '' ? null : $match[2];
        }

        return $releases;
    }

    /**
     * Returns the highest released version, or null if there is none.
     */
    public function lastRelease(): ?string
    {
        $versions = array_map(strval(...), array_keys($this->releases()));
        usort($versions, static fn (string $a, string $b): int => version_compare($a, $b));

        return $versions === [] ? null : $versions[count($versions) - 1];
    }

    /**
     * Checks whether the version has a dated heading.
     */
    public function isReleased(string $version): bool
    {
        return ($this->releases()[$version] ?? null) !== null;
    }

    /**
     * Returns the number of list entries under `## [Unreleased]`.
     */
    public function unreleasedEntries(): int
    {
        if (preg_match('/^## \[Unreleased\][^\n]*\n(.*?)(?=^## |^\[[^\]]+\]: |\z)/ms', $this->content, $match) !== 1) {
            return 0;
        }

        return (int) preg_match_all('/^\s*[-*] /m', $match[1]);
    }
}

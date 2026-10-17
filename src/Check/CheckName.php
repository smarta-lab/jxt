<?php

declare(strict_types=1);

namespace SmartA\Jxt\Check;

/**
 * Names of all checks of `jxt check`, in the form `group/check`.
 *
 * The groups appear in the output in the order of their first check.
 */
enum CheckName: string
{
    case ManifestVersionSemver = 'manifest/version-semver';
    case ManifestTagVersion = 'manifest/tag-version';
    case ManifestListedFiles = 'manifest/listed-files';
    case ManifestUnlistedFiles = 'manifest/unlisted-files';
    case ManifestStrayFiles = 'manifest/stray-files';
    case EditorConfigMissing = 'editorconfig/missing';
    case EditorConfigIndentStyle = 'editorconfig/indent-style';
    case EditorConfigEndOfLine = 'editorconfig/end-of-line';
    case EditorConfigFinalNewline = 'editorconfig/final-newline';
    case EditorConfigTrailingWhitespace = 'editorconfig/trailing-whitespace';
    case EditorConfigMaxLineLength = 'editorconfig/max-line-length';
    case EditorConfigCharset = 'editorconfig/charset';
    case ChangelogFormat = 'changelog/format';
    case ChangelogLastRelease = 'changelog/last-release';
    case ChangelogUnreleased = 'changelog/unreleased';
    case ChangelogReleaseHeading = 'changelog/release-heading';
    case AssetsJson = 'assets/json';
    case AssetsUri = 'assets/uri';
    case AssetsVersionChanged = 'assets/version-changed';
    case AssetsVersionCoupled = 'assets/version-coupled';
    case AssetsBaseline = 'assets/baseline';
    case LanguageParse = 'language/parse';
    case LanguageFiles = 'language/files';
    case LanguageKeys = 'language/keys';
    case LanguageUndefined = 'language/undefined';
    case LanguageUnused = 'language/unused';
    case ComposerVersionField = 'composer/version-field';

    /**
     * Returns the group, e.g. `manifest` for `manifest/version-semver`.
     */
    public function group(): string
    {
        return explode('/', $this->value)[0];
    }

    /**
     * Returns all groups in output order.
     *
     * @return list<string>
     */
    public static function groups(): array
    {
        return array_values(array_unique(array_map(static fn (self $check): string => $check->group(), self::cases())));
    }

    /**
     * Checks whether a name denotes a group or a single check.
     */
    public static function isKnown(string $name): bool
    {
        return self::tryFrom($name) !== null || in_array($name, self::groups(), true);
    }
}

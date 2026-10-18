<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Git;

/**
 * Relation between Git tags and extension versions.
 */
final class VersionTag
{
    /**
     * Checks whether the tag names the version, with or without a `v` prefix.
     */
    public static function names(string $tag, ?string $version): bool
    {
        return $version !== null && ($tag === $version || $tag === 'v' . $version);
    }
}

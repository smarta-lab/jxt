<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Check;

/**
 * What a checker checks, which decides how often it runs for a package.
 */
enum CheckScope: string
{
    /** The project as a whole (changelog, Composer, EditorConfig): once, also for a package. */
    case Project = 'project';

    /** An extension (manifest, assets, language files): for a package, the package and each of its extensions. */
    case Extension = 'extension';

    /** The entries of a package manifest: only for a package. */
    case Package = 'package';
}

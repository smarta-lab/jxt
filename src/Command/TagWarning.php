<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Command;

use SmartA\Jxt\Build\ProjectBuild;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Warning of `jxt build --tag` and `jxt install --tag` when the tag does not name the manifest version.
 */
final class TagWarning
{
    /**
     * Shows the warning if the build comes from a tag that does not name the manifest version.
     */
    public static function show(SymfonyStyle $io, ProjectBuild $build): void
    {
        if (!$build->tagMatchesVersion()) {
            $io->warning(sprintf(
                'Tag %s does not match the manifest version %s in the tag.',
                $build->tag,
                $build->manifest->version ?? '(none)',
            ));
        }
    }
}

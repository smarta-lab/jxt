<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Configuration;

/**
 * Where a configuration key may be set.
 */
enum KeyScope
{
    /**
     * Only in the project configuration `.jxt.json`, since it describes the project.
     */
    case Project;

    /**
     * Only at the top level of the global configuration.
     */
    case Global;

    /**
     * Only in a site of `sites` in the global configuration, since it describes this machine and may run commands.
     */
    case Site;

    /**
     * At the top level of both files; the project configuration takes precedence.
     */
    case Anywhere;
}

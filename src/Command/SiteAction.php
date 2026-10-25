<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Command;

/**
 * Actions of the `jxt site` command.
 */
enum SiteAction: string
{
    case List = 'list';
    case Add = 'add';
    case Remove = 'remove';
    case Rename = 'rename';
}

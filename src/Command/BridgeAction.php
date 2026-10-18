<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Command;

/**
 * Actions of the `jxt bridge` command.
 */
enum BridgeAction: string
{
    case Status = 'status';
    case Add = 'add';
    case Remove = 'remove';
}

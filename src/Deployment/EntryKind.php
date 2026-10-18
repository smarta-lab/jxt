<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Deployment;

/**
 * Kind of an entry in the deployment plan.
 */
enum EntryKind: string
{
    case File = 'file';
    case Directory = 'directory';
}

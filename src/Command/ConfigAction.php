<?php

declare(strict_types=1);

namespace SmartA\Jxt\Command;

/**
 * Actions of the `jxt config` command.
 */
enum ConfigAction: string
{
    case Show = 'show';
    case Set = 'set';
    case Unset = 'unset';
}

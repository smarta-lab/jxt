<?php

declare(strict_types=1);

namespace SmartA\Jxt\Check;

/**
 * Whether the manifest version is already released (pre-check for the next one) or about to be (final check).
 */
enum CheckMode: string
{
    case PreCheck = 'pre-release check';
    case FinalCheck = 'release check';
}

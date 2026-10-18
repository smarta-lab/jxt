<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Git;

use RuntimeException;
use SmartA\Jxt\JxtException;

/**
 * Thrown when a Git command fails or refers to a missing tag.
 */
final class GitException extends RuntimeException implements JxtException
{
}

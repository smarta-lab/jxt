<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Command;

use RuntimeException;
use SmartA\Jxt\JxtException;

/**
 * Thrown when a command that does not support packages yet finds a package manifest.
 */
final class UnsupportedPackageException extends RuntimeException implements JxtException
{
}

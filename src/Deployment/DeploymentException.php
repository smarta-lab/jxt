<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Deployment;

use RuntimeException;
use SmartA\Jxt\JxtException;

/**
 * Thrown when source files are missing or file operations fail.
 */
final class DeploymentException extends RuntimeException implements JxtException
{
}

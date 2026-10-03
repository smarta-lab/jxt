<?php

declare(strict_types=1);

namespace SmartA\Jxt\Build;

use RuntimeException;
use SmartA\Jxt\JxtException;

/**
 * Thrown when the ZIP file cannot be created.
 */
final class BuildException extends RuntimeException implements JxtException
{
}

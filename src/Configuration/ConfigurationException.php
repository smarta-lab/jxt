<?php

declare(strict_types=1);

namespace SmartA\Jxt\Configuration;

use RuntimeException;
use SmartA\Jxt\JxtException;

/**
 * Thrown when the configuration is missing, unreadable or contains invalid values.
 */
final class ConfigurationException extends RuntimeException implements JxtException
{
}

<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Manifest;

use RuntimeException;
use SmartA\Jxt\JxtException;

/**
 * Thrown when a manifest is missing, unreadable or contains invalid data.
 */
final class ManifestException extends RuntimeException implements JxtException
{
}

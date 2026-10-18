<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Check;

use RuntimeException;
use SmartA\Jxt\JxtException;

/**
 * Thrown for an invalid call of `jxt check`, e.g. an unknown check name.
 */
final class CheckException extends RuntimeException implements JxtException
{
}

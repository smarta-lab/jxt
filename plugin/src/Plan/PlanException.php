<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Plugin\Console\Jxt\Plan;

use RuntimeException;

/**
 * Thrown when the plan is unreadable, invalid or cannot be applied.
 */
final class PlanException extends RuntimeException
{
}

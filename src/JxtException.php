<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt;

use Throwable;

/**
 * Common interface of all domain errors, which are reported as a message without a stack trace.
 */
interface JxtException extends Throwable
{
}

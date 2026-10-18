<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Bridge;

use RuntimeException;
use SmartA\Jxt\JxtException;

/**
 * Thrown when the bridge plugin cannot be built, staged for or queried.
 */
final class BridgeException extends RuntimeException implements JxtException
{
}

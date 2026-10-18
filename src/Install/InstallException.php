<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Install;

use RuntimeException;
use SmartA\Jxt\JxtException;

/**
 * Error while handing a package over to the Joomla CLI.
 */
final class InstallException extends RuntimeException implements JxtException
{
}

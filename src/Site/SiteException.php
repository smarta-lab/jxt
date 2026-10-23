<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Site;

use RuntimeException;
use SmartA\Jxt\JxtException;

/**
 * Error in accessing the test site, e.g. a path the Joomla CLI cannot see.
 */
final class SiteException extends RuntimeException implements JxtException
{
}

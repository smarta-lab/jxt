<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\EditorConfig;

use RuntimeException;
use SmartA\Jxt\JxtException;

/**
 * Thrown when an `.editorconfig` file cannot be read or a path cannot be resolved.
 */
final class EditorConfigException extends RuntimeException implements JxtException
{
}

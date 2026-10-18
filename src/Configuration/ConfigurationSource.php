<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Configuration;

/**
 * Origin of a configuration value as shown by `jxt config`.
 */
enum ConfigurationSource: string
{
    case Project = 'project';
    case Global = 'global';
    case Default = 'default';
    case None = 'not set';
}

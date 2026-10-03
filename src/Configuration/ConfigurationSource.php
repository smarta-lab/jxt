<?php

declare(strict_types=1);

namespace SmartA\Jxt\Configuration;

/**
 * Origin of a configuration value as shown by `jxt config`.
 */
enum ConfigurationSource: string
{
    case Project = 'Projekt';
    case Global = 'global';
    case Default = 'Standard';
    case None = 'nicht gesetzt';
}

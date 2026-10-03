<?php

declare(strict_types=1);

namespace SmartA\Jxt\Configuration;

/**
 * A configuration value with its origin.
 */
final readonly class ConfigurationEntry
{
    public function __construct(
        public ConfigurationKey $key,
        public ?string $value,
        public ConfigurationSource $source,
    ) {
    }
}

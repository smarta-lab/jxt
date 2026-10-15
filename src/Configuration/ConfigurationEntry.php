<?php

declare(strict_types=1);

namespace SmartA\Jxt\Configuration;

/**
 * A configuration value with its origin.
 */
final readonly class ConfigurationEntry
{
    /**
     * @param string|list<string>|null $value
     */
    public function __construct(
        public ConfigurationKey $key,
        public string|array|null $value,
        public ConfigurationSource $source,
    ) {
    }
}

<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Configuration;

/**
 * A configuration value with its origin.
 *
 * @phpstan-import-type ConfigurationValue from ConfigurationKey
 */
final readonly class ConfigurationEntry
{
    /**
     * @param ConfigurationValue $value
     */
    public function __construct(
        public ConfigurationKey $key,
        public string|array|null $value,
        public ConfigurationSource $source,
    ) {
    }
}

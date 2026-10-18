<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Plugin\Console\Jxt\Plan;

/**
 * Identifies the deployed extension in `#__extensions`, so that its manifest cache can be refreshed.
 */
final readonly class ExtensionIdentity
{
    /**
     * @param string $type     `module`, `template` or `plugin`
     * @param string $folder   Plugin group, empty for other types
     * @param int    $clientId 0 for site, 1 for administrator
     */
    public function __construct(
        public string $type,
        public string $element,
        public string $folder,
        public int $clientId,
    ) {
    }
}

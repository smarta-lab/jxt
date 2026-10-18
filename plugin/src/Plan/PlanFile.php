<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Plugin\Console\Jxt\Plan;

/**
 * File of the extension, relative to the Joomla root directory.
 */
final readonly class PlanFile
{
    /**
     * @param string|null $source Staged copy relative to the staging directory; null if the file is unchanged
     *                            and only its mode is set
     */
    public function __construct(
        public string $target,
        public ?string $source,
    ) {
    }
}

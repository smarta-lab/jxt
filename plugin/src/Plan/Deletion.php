<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Plugin\Console\Jxt\Plan;

/**
 * Entry to delete, relative to the Joomla root directory.
 */
final readonly class Deletion
{
    /**
     * @param bool $directory Whether the entry is an (empty) directory instead of a file
     */
    public function __construct(
        public string $target,
        public bool $directory,
    ) {
    }
}

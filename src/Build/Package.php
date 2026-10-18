<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Build;

/**
 * Result of `jxt build`: the created ZIP file.
 */
final readonly class Package
{
    /**
     * @param string $path      Absolute path of the ZIP file
     * @param int    $fileCount Number of files contained
     */
    public function __construct(
        public string $path,
        public int $fileCount,
    ) {
    }
}

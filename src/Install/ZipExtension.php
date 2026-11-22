<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Install;

use SmartA\Jxt\Deployment\PlanEntry;
use SmartA\Jxt\Manifest\Manifest;

/**
 * Extension in a ZIP file with the files that the Joomla installer installs from it.
 */
final readonly class ZipExtension
{
    /**
     * @param list<PlanEntry> $files Files of the plan contained in the ZIP file, with their targets
     */
    public function __construct(
        public Manifest $manifest,
        public array $files,
    ) {
    }
}

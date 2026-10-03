<?php

declare(strict_types=1);

namespace SmartA\Jxt\Deployment;

/**
 * Mapping "source → target" for a file or a directory.
 */
final readonly class PlanEntry
{
    /**
     * @param string $source Path relative to the extension's project directory
     * @param string $target Path relative to the Joomla root directory
     */
    public function __construct(
        public string $source,
        public string $target,
        public EntryKind $kind,
    ) {
    }
}

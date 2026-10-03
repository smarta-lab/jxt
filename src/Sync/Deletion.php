<?php

declare(strict_types=1);

namespace SmartA\Jxt\Sync;

use SmartA\Jxt\Deployment\EntryKind;

/**
 * A file or (by then empty) directory to delete on the server.
 */
final readonly class Deletion
{
    /**
     * @param string $target Path relative to the Joomla root directory
     */
    public function __construct(
        public string $target,
        public EntryKind $kind,
    ) {
    }
}

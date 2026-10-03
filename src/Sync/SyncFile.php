<?php

declare(strict_types=1);

namespace SmartA\Jxt\Sync;

use SmartA\Jxt\Deployment\PlanEntry;

/**
 * A file to transfer and its status on the server.
 */
final readonly class SyncFile
{
    public function __construct(
        public PlanEntry $entry,
        public FileStatus $status,
    ) {
    }
}

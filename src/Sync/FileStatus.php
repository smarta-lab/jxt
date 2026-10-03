<?php

declare(strict_types=1);

namespace SmartA\Jxt\Sync;

/**
 * Status of a project file compared with the file on the server.
 */
enum FileStatus: string
{
    case New = 'new';
    case Changed = 'changed';
    case Unchanged = 'unchanged';
}

<?php

declare(strict_types=1);

namespace SmartA\Jxt\Install;

/**
 * Result of a finished process.
 */
final readonly class ProcessResult
{
    /**
     * @param int    $exitCode Exit code of the process
     * @param string $output   Standard output and standard error in the order written
     */
    public function __construct(
        public int $exitCode,
        public string $output,
    ) {
    }
}

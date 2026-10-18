<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

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

    /**
     * Tells whether sudo refused to run the command, e.g. because a sudoers rule is missing.
     */
    public function sudoRefused(): bool
    {
        return preg_match('/^sudo: /m', $this->output) === 1;
    }
}

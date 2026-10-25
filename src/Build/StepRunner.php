<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Build;

use SmartA\Jxt\Install\ProcessResult;

/**
 * Runs a prepare step as an external program and waits for it to finish.
 */
interface StepRunner
{
    /**
     * Runs the command without a shell in the directory, with empty standard input.
     *
     * @param list<string> $command     Program and arguments
     * @param bool         $passThrough Whether the output goes directly to the terminal; otherwise it is collected
     *                                  in the result
     *
     * @throws BuildException if the process cannot be started
     */
    public function run(array $command, string $directory, bool $passThrough): ProcessResult;
}

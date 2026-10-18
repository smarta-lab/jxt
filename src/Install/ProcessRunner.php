<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Install;

/**
 * Runs an external program and waits for it to finish.
 */
interface ProcessRunner
{
    /**
     * Runs the command without a shell.
     *
     * @param list<string> $command Program and arguments
     *
     * @throws InstallException if the process cannot be started
     */
    public function run(array $command): ProcessResult;
}

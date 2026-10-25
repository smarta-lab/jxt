<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Build;

use SmartA\Jxt\Install\ProcessResult;
use SmartA\Jxt\PhpErrors;

/**
 * Runs prepare steps via `proc_open`. Passed through, the step writes to the streams of jxt itself, so that tools
 * see a terminal and show colors and progress; collected, standard output and standard error go to one temporary
 * file, so the order of the messages is kept.
 */
final readonly class SystemStepRunner implements StepRunner
{
    /**
     * @param resource|null $stdout Stream for the standard output passed through; null for the one of jxt
     * @param resource|null $stderr Stream for the standard error passed through; null for the one of jxt
     */
    public function __construct(private mixed $stdout = null, private mixed $stderr = null)
    {
    }

    #[\Override]
    public function run(array $command, string $directory, bool $passThrough): ProcessResult
    {
        $error = sprintf('Cannot start %s', $command[0] ?? 'process');
        $input = fopen('/dev/null', 'r');
        $output = $passThrough ? null : tmpfile();

        if ($input === false || $output === false) {
            throw new BuildException($error . '.');
        }

        $descriptors = [0 => $input, 1 => $output ?? $this->stdout ?? STDOUT, 2 => $output ?? $this->stderr ?? STDERR];
        [$process, $message] = PhpErrors::capture(
            static fn (): mixed => proc_open($command, $descriptors, $pipes, $directory),
        );

        if (!is_resource($process)) {
            throw new BuildException($error . ($message === null ? '.' : ': ' . $message));
        }

        $exitCode = proc_close($process);

        if ($output === null) {
            return new ProcessResult($exitCode, '');
        }

        rewind($output);

        return new ProcessResult($exitCode, (string) stream_get_contents($output));
    }
}

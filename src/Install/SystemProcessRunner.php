<?php

declare(strict_types=1);

namespace SmartA\Jxt\Install;

/**
 * Runs processes via `proc_open` and collects standard output and standard error in one temporary file,
 * so the order of the messages is kept.
 */
final readonly class SystemProcessRunner implements ProcessRunner
{
    #[\Override]
    public function run(array $command): ProcessResult
    {
        $error = sprintf('Cannot start %s.', $command[0] ?? 'process');
        $input = fopen('/dev/null', 'r');
        $output = tmpfile();

        if ($input === false || $output === false) {
            throw new InstallException($error);
        }

        $process = proc_open($command, [0 => $input, 1 => $output, 2 => $output], $pipes)
            ?: throw new InstallException($error);

        $exitCode = proc_close($process);
        rewind($output);

        return new ProcessResult($exitCode, (string) stream_get_contents($output));
    }
}

<?php

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Install;

use SmartA\Jxt\Install\ProcessResult;
use SmartA\Jxt\Install\ProcessRunner;

/**
 * Records the command and the package passed via `--path=` instead of running a process.
 */
final class RecordingProcessRunner implements ProcessRunner
{
    /** @var list<string> */
    public array $command = [];

    public ?string $packageContent = null;

    public ?int $packageMode = null;

    public function __construct(private readonly ProcessResult $result)
    {
    }

    #[\Override]
    public function run(array $command): ProcessResult
    {
        $this->command = $command;
        $path = substr((string) end($command), strlen('--path='));

        if (is_file($path)) {
            $this->packageContent = (string) file_get_contents($path);
            $this->packageMode = (int) fileperms($path) & 0777;
        }

        return $this->result;
    }
}

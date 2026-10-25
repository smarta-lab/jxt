<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Build;

use Closure;
use SmartA\Jxt\Build\StepRunner;
use SmartA\Jxt\Install\ProcessResult;

/**
 * Step runner for tests: records the commands and returns the exit code and output of a callback, which may also
 * create files like a real step.
 */
final class ScriptedStepRunner implements StepRunner
{
    /** @var list<array{list<string>, string, bool}> Command, directory and pass-through flag of every run */
    public array $runs = [];

    /**
     * @param (Closure(list<string>, string): ProcessResult)|null $script Result of a command in a directory;
     *                                                                    null for success without output
     */
    public function __construct(private readonly ?Closure $script = null)
    {
    }

    #[\Override]
    public function run(array $command, string $directory, bool $passThrough): ProcessResult
    {
        $this->runs[] = [$command, $directory, $passThrough];

        return $this->script === null ? new ProcessResult(0, '') : ($this->script)($command, $directory);
    }

    /**
     * Returns the commands that were run.
     *
     * @return list<list<string>>
     */
    public function commands(): array
    {
        return array_map(static fn (array $run): array => $run[0], $this->runs);
    }
}

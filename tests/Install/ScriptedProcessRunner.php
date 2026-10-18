<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Install;

use Closure;
use SmartA\Jxt\Install\ProcessResult;
use SmartA\Jxt\Install\ProcessRunner;

/**
 * Records every command and answers with the given results in order; the last result repeats.
 *
 * An inspector gets the last argument of each command while it runs, e.g. to look at a staged plan or ZIP file
 * that is removed afterwards.
 */
final class ScriptedProcessRunner implements ProcessRunner
{
    /** @var list<list<string>> */
    public array $commands = [];

    /** @var list<ProcessResult> */
    private array $results;

    /** @var (Closure(string): void)|null */
    public ?Closure $inspector = null;

    public function __construct(ProcessResult $result, ProcessResult ...$results)
    {
        $this->results = [$result, ...array_values($results)];
    }

    #[\Override]
    public function run(array $command): ProcessResult
    {
        $this->commands[] = $command;

        if ($this->inspector !== null) {
            ($this->inspector)((string) end($command));
        }

        return count($this->results) > 1 ? array_shift($this->results) : $this->results[0];
    }

    /**
     * Returns the arguments of the Joomla CLI of every command, without PHP binary, script and sudo prefix.
     *
     * @return list<list<string>>
     */
    public function cliArguments(): array
    {
        return array_map(
            static fn (array $command): array => array_slice(
                $command,
                (int) array_key_first(array_filter($command, static fn (string $part): bool => str_ends_with($part, '/cli/joomla.php'))) + 1,
            ),
            $this->commands,
        );
    }
}

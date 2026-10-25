<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Build;

use SmartA\Jxt\Configuration\PrepareStep;
use SmartA\Jxt\Install\CommandLine;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the steps of the `prepare` setting, which generate files before jxt builds, checks or syncs the extension.
 *
 * The output of the steps is shown as it comes; with `--quiet`, it is collected and only becomes part of the error
 * message of a failing step. Under sudo, no step runs, since the generated files would be owned by root.
 */
final readonly class Preparation
{
    /**
     * @param bool $runsViaSudo Whether jxt runs via sudo
     */
    public function __construct(
        private StepRunner $runner = new SystemStepRunner(),
        private bool $runsViaSudo = false,
    ) {
    }

    /**
     * Runs the steps in order in the directory and stops at the first failing step.
     *
     * @param list<PrepareStep> $steps
     * @param bool              $forSync Whether the steps run for `jxt sync`, which uses their replacements
     *
     * @throws BuildException if jxt runs via sudo, or a step cannot be started or fails
     */
    public function run(array $steps, string $directory, OutputInterface $output, bool $forSync = false): void
    {
        if ($steps === []) {
            return;
        }

        if ($this->runsViaSudo) {
            throw new BuildException(
                'Please run jxt without sudo: the prepare steps would create files owned by root.',
            );
        }

        $passThrough = $output->getVerbosity() > OutputInterface::VERBOSITY_QUIET;

        foreach ($steps as $step) {
            $command = $step->command($forSync);

            if ($command === null) {
                $output->writeln('<comment>Skipped for sync:</comment> ' . $this->format($step->run));

                continue;
            }

            $output->writeln(sprintf(
                '<comment>></comment> %s%s',
                $this->format($command),
                $command === $step->run ? '' : sprintf(' (sync, instead of %s)', $this->format($step->run)),
            ));
            $this->runStep($command, $directory, $passThrough);
        }
    }

    /**
     * Runs a single step.
     *
     * @param non-empty-list<string> $command
     *
     * @throws BuildException if the step cannot be started or fails
     */
    private function runStep(array $command, string $directory, bool $passThrough): void
    {
        $result = $this->runner->run($command, $directory, $passThrough);

        if ($result->exitCode !== 0) {
            $collected = trim($result->output);

            throw new BuildException(
                sprintf('Prepare step failed (exit code %d): %s', $result->exitCode, CommandLine::format($command))
                . ($passThrough || $collected === '' ? '' : "\n" . $collected),
            );
        }
    }

    /**
     * Formats a command as command line, escaped for the console formatter.
     *
     * @param list<string> $command
     */
    private function format(array $command): string
    {
        return OutputFormatter::escape(CommandLine::format($command));
    }
}

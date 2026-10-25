<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Build;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Build\BuildException;
use SmartA\Jxt\Build\Preparation;
use SmartA\Jxt\Configuration\PrepareStep;
use SmartA\Jxt\Install\ProcessResult;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Tests for running the prepare steps.
 */
final class PreparationTest extends TestCase
{
    public function testRunsTheStepsInOrderInTheDirectory(): void
    {
        $runner = new ScriptedStepRunner();
        $output = new BufferedOutput();

        (new Preparation($runner))->run(
            [new PrepareStep(['npm', 'run', 'css']), new PrepareStep(['php', 'build/version.php'], false)],
            '/project',
            $output,
        );

        self::assertSame([
            [['npm', 'run', 'css'], '/project', true],
            [['php', 'build/version.php'], '/project', true],
        ], $runner->runs);
        self::assertSame("> npm run css\n> php build/version.php\n", $output->fetch());
    }

    public function testUsesTheReplacementsForSync(): void
    {
        $runner = new ScriptedStepRunner();
        $output = new BufferedOutput();

        (new Preparation($runner))->run([
            new PrepareStep(['npm', 'run', 'css']),
            new PrepareStep(['npm', 'run', 'minify'], ['npm', 'run', 'minify:copy']),
            new PrepareStep(['npm', 'run', 'images'], false),
        ], '/project', $output, true);

        self::assertSame([['npm', 'run', 'css'], ['npm', 'run', 'minify:copy']], $runner->commands());
        self::assertSame(
            "> npm run css\n> npm run minify:copy (sync, instead of npm run minify)\nSkipped for sync: npm run images\n",
            $output->fetch(),
        );
    }

    public function testStopsAtTheFirstFailingStep(): void
    {
        $runner = new ScriptedStepRunner(static fn (array $command): ProcessResult => new ProcessResult(2, 'not shown'));

        try {
            (new Preparation($runner))->run(
                [new PrepareStep(['npm', 'run', 'build']), new PrepareStep(['npm', 'run', 'minify'])],
                '/project',
                new BufferedOutput(),
            );
            self::fail('Expected a BuildException.');
        } catch (BuildException $exception) {
            self::assertSame('Prepare step failed (exit code 2): npm run build', $exception->getMessage());
        }

        self::assertSame([['npm', 'run', 'build']], $runner->commands());
    }

    public function testCollectsTheOutputWhenQuietAndReportsItOnFailure(): void
    {
        $runner = new ScriptedStepRunner(static fn (array $command): ProcessResult => new ProcessResult(1, "sass: error\n"));

        $this->expectException(BuildException::class);
        $this->expectExceptionMessage("Prepare step failed (exit code 1): sass in.scss\nsass: error");

        try {
            (new Preparation($runner))->run([new PrepareStep(['sass', 'in.scss'])], '/project', new BufferedOutput(OutputInterface::VERBOSITY_QUIET));
        } finally {
            self::assertFalse($runner->runs[0][2]);
        }
    }

    public function testEscapesCommandsInTheOutput(): void
    {
        $output = new BufferedOutput();

        (new Preparation(new ScriptedStepRunner()))->run([new PrepareStep(['echo', '<info>'])], '/project', $output);

        self::assertSame("> echo '<info>'\n", $output->fetch());
    }

    public function testRefusesToRunStepsViaSudo(): void
    {
        $runner = new ScriptedStepRunner();

        $this->expectException(BuildException::class);
        $this->expectExceptionMessage('Please run jxt without sudo');

        try {
            (new Preparation($runner, true))->run([new PrepareStep(['npm', 'run', 'build'])], '/project', new BufferedOutput());
        } finally {
            self::assertSame([], $runner->runs);
        }
    }

    public function testDoesNothingWithoutStepsEvenViaSudo(): void
    {
        $output = new BufferedOutput();

        (new Preparation(new ScriptedStepRunner(), true))->run([], '/project', $output);

        self::assertSame('', $output->fetch());
    }
}

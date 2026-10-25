<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Build;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Build\BuildException;
use SmartA\Jxt\Build\SystemStepRunner;
use SmartA\Jxt\Tests\TemporaryDirectoryTrait;

/**
 * Tests for running a prepare step as a process.
 */
final class SystemStepRunnerTest extends TestCase
{
    use TemporaryDirectoryTrait;

    #[\Override]
    protected function setUp(): void
    {
        $this->createTemporaryDirectory();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testRunsInTheDirectoryAndCollectsTheOutput(): void
    {
        $result = (new SystemStepRunner())->run(
            [PHP_BINARY, '-r', 'echo getcwd(), " "; fwrite(STDERR, "err"); exit(3);'],
            $this->temporaryDirectory,
            false,
        );

        self::assertSame(3, $result->exitCode);
        self::assertSame((string) realpath($this->temporaryDirectory) . ' err', $result->output);
    }

    public function testPassesTheOutputThrough(): void
    {
        $stdout = tmpfile();
        $stderr = tmpfile();
        self::assertNotFalse($stdout);
        self::assertNotFalse($stderr);

        $result = (new SystemStepRunner($stdout, $stderr))->run(
            [PHP_BINARY, '-r', 'echo "out"; fwrite(STDERR, "err"); echo stream_get_contents(STDIN);'],
            $this->temporaryDirectory,
            true,
        );

        rewind($stdout);
        rewind($stderr);
        self::assertSame(0, $result->exitCode);
        self::assertSame('', $result->output);
        self::assertSame('out', stream_get_contents($stdout));
        self::assertSame('err', stream_get_contents($stderr));
    }

    public function testPassesArgumentsWithoutShell(): void
    {
        $result = (new SystemStepRunner())->run([PHP_BINARY, '-r', 'echo $argv[1];', '$HOME; echo x'], $this->temporaryDirectory, false);

        self::assertSame('$HOME; echo x', $result->output);
    }

    public function testReportsAProgramThatCannotBeStarted(): void
    {
        $this->expectException(BuildException::class);
        $this->expectExceptionMessageMatches('/^Cannot start jxt-missing-program: .*No such file or directory/');

        (new SystemStepRunner())->run(['jxt-missing-program'], $this->temporaryDirectory, false);
    }
}

<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Install;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Install\SystemProcessRunner;

/**
 * Tests for running a process without a shell.
 */
final class SystemProcessRunnerTest extends TestCase
{
    public function testReturnsExitCodeAndCombinedOutput(): void
    {
        $result = (new SystemProcessRunner())->run(
            [PHP_BINARY, '-r', 'echo "out "; fwrite(STDERR, "err"); exit(3);'],
        );

        self::assertSame(3, $result->exitCode);
        self::assertSame('out err', $result->output);
    }

    public function testPassesArgumentsWithoutShell(): void
    {
        $result = (new SystemProcessRunner())->run([PHP_BINARY, '-r', 'echo $argv[1];', '$HOME; echo x']);

        self::assertSame('$HOME; echo x', $result->output);
    }
}

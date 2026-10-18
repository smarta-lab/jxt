<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests;

use PHPUnit\Framework\Assert;
use SmartA\Jxt\Install\SystemProcessRunner;

/**
 * Creates and changes a Git repository in the temporary directory of `TemporaryDirectoryTrait`.
 */
trait GitTrait
{
    /**
     * Creates a Git repository in the temporary directory with a commit of the current files.
     */
    private function initGit(): void
    {
        $this->git('init', '--quiet');
        $this->commit('Initial commit');
    }

    /**
     * Commits all files.
     */
    private function commit(string $message): void
    {
        $this->git('add', '--all');
        $this->git('commit', '--quiet', '--allow-empty', '-m', $message);
    }

    /**
     * Runs Git in the temporary directory and fails the test on errors.
     */
    private function git(string ...$arguments): void
    {
        $result = (new SystemProcessRunner())->run([
            'git', '-C', $this->temporaryDirectory, '-c', 'user.name=Test', '-c', 'user.email=test@example.org',
            '-c', 'commit.gpgsign=false', '-c', 'tag.gpgsign=false', ...array_values($arguments),
        ]);

        Assert::assertSame(0, $result->exitCode, $result->output);
    }
}

<?php

declare(strict_types=1);

namespace SmartA\Jxt\Check;

use SmartA\Jxt\Install\InstallException;
use SmartA\Jxt\Install\ProcessResult;
use SmartA\Jxt\Install\ProcessRunner;

/**
 * Read-only access to the Git repository that contains the project directory.
 *
 * Paths are relative to the project directory, also when it is a subdirectory of the repository.
 */
final readonly class GitRepository
{
    private function __construct(private string $directory, private ProcessRunner $runner)
    {
    }

    /**
     * Returns the repository of the directory, or null if it is not in a Git work tree or Git is missing.
     */
    public static function open(string $directory, ProcessRunner $runner): ?self
    {
        $repository = new self($directory, $runner);

        try {
            $result = $repository->run('rev-parse', '--is-inside-work-tree');
        } catch (InstallException) {
            return null;
        }

        return $result->exitCode === 0 && trim($result->output) === 'true' ? $repository : null;
    }

    /**
     * Returns the names of all tags.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        return $this->lines(str_replace("\r", '', $this->run('tag', '--list')->output), "\n");
    }

    /**
     * Returns the tracked and the untracked, not ignored files below the directory.
     *
     * @return list<string>
     */
    public function files(): array
    {
        return $this->lines($this->run('ls-files', '-z', '--cached', '--others', '--exclude-standard')->output, "\0");
    }

    /**
     * Checks whether a file in the work tree differs from its state in the tag.
     */
    public function hasChanged(string $tag, string $path): bool
    {
        return $this->run('diff', '--quiet', $tag, '--', $path)->exitCode === 1;
    }

    /**
     * Returns the content of a file in the tag, or null if it does not exist there.
     */
    public function show(string $tag, string $path): ?string
    {
        $result = $this->run('show', $tag . ':./' . $path);

        return $result->exitCode === 0 ? $result->output : null;
    }

    /**
     * Runs a Git command in the directory.
     *
     * @throws InstallException if Git cannot be started
     */
    private function run(string ...$arguments): ProcessResult
    {
        return $this->runner->run(['git', '-C', $this->directory, ...array_values($arguments)]);
    }

    /**
     * Splits the output into non-empty entries.
     *
     * @param non-empty-string $separator
     *
     * @return list<string>
     */
    private function lines(string $output, string $separator): array
    {
        return array_values(array_filter(explode($separator, $output), static fn (string $line): bool => $line !== ''));
    }
}

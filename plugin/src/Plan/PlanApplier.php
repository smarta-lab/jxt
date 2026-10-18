<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Plugin\Console\Jxt\Plan;

use Closure;

/**
 * Applies a plan in the Joomla root directory, in the same order as a direct `jxt sync`.
 *
 * Deletions come first, then the directories of the extension and the files; missing directories along a path
 * are created, and modes are set for the extension's entries and for created directories only.
 */
final class PlanApplier
{
    /**
     * Applies the plan.
     *
     * @param string $stagingDirectory Directory with `plan.json` and the staged files
     *
     * @throws PlanException if a file operation fails
     */
    public function apply(Plan $plan, string $stagingDirectory, string $root): void
    {
        foreach ($plan->deletions as $deletion) {
            $path = $root . '/' . $deletion->target;

            if ($deletion->directory) {
                $this->run(static fn (): bool => rmdir($path), sprintf('Cannot delete directory %s', $path));
            } else {
                $this->run(static fn (): bool => unlink($path), sprintf('Cannot delete %s', $path));
            }
        }

        foreach ($plan->directories as $directory) {
            $this->ensureDirectory($root, $directory, $plan->directoryMode);
            $this->changeMode($root . '/' . $directory, $plan->directoryMode);
        }

        foreach ($plan->files as $file) {
            $target = $root . '/' . $file->target;
            $this->ensureDirectory($root, dirname($file->target), $plan->directoryMode);

            if ($file->source !== null) {
                $source = $stagingDirectory . '/' . $file->source;
                $this->run(static fn (): bool => copy($source, $target), sprintf('Cannot copy %s to %s', $source, $target));
            }

            $this->changeMode($target, $plan->fileMode);
        }
    }

    /**
     * Creates missing directories along the path and sets the mode only for those.
     */
    private function ensureDirectory(string $root, string $directory, int $mode): void
    {
        $path = $root;

        foreach (explode('/', $directory) as $segment) {
            $path .= '/' . $segment;

            if (!is_dir($path)) {
                $this->run(static fn (): bool => mkdir($path), sprintf('Cannot create directory %s', $path));
                $this->changeMode($path, $mode);
            }
        }
    }

    /**
     * Sets the mode if it differs.
     */
    private function changeMode(string $path, int $mode): void
    {
        clearstatcache(true, $path);

        if (((int) fileperms($path) & 0777) !== $mode) {
            $this->run(static fn (): bool => chmod($path, $mode), sprintf('Cannot set the mode of %s', $path));
        }
    }

    /**
     * Runs a file operation and turns a failure, including PHP's warning, into an exception.
     *
     * @param Closure(): bool $operation
     *
     * @throws PlanException if the operation fails
     */
    private function run(Closure $operation, string $message): void
    {
        $error = null;
        set_error_handler(static function (int $level, string $text) use (&$error): bool {
            $error = $text;

            return true;
        });

        try {
            $succeeded = $operation();
        } finally {
            restore_error_handler();
        }

        if (!$succeeded) {
            throw new PlanException($message . ($error === null ? '.' : ': ' . $error));
        }
    }
}

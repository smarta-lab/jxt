<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Bridge;

use Closure;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SmartA\Jxt\Deployment\EntryKind;
use SmartA\Jxt\Manifest\ClientType;
use SmartA\Jxt\Manifest\Manifest;
use SmartA\Jxt\PhpErrors;
use SmartA\Jxt\Sync\Deletion;
use SmartA\Jxt\Sync\FileStatus;
use SmartA\Jxt\Sync\SyncFile;
use SmartA\Jxt\Sync\SyncExecutor;
use SmartA\Jxt\Sync\SyncPlan;
use SplFileInfo;

/**
 * Stages a sync plan for the bridge plugin: `plan.json` and copies of the new and changed files in a directory
 * below the temporary directory.
 *
 * The web server user cannot read the project directory, so the directory gets mode 0755 and the files 0644.
 * Other users cannot change them, since the directory belongs to the developer.
 */
final readonly class PlanStager
{
    public const string PLAN_FILE = 'plan.json';

    /**
     * Format of the plan; the bridge rejects formats it does not read. Raise it for an incompatible change and
     * keep the bridge reading the previous format if that is easy.
     */
    public const int FORMAT = 1;

    /**
     * @param string|null $temporaryDirectory Parent of the staging directory; null for the system default
     */
    public function __construct(private ?string $temporaryDirectory = null)
    {
    }

    /**
     * Creates the staging directory and returns its path.
     *
     * @param string|null $parentDirectory Parent of the staging directory, e.g. the site's `tmp/` directory;
     *                                     null for the one given to the constructor
     *
     * @throws BridgeException if a directory or file cannot be written
     */
    public function stage(
        SyncPlan $plan,
        Manifest $manifest,
        string $extensionDirectory,
        ?string $parentDirectory = null,
    ): string {
        $parent = $parentDirectory ?? $this->temporaryDirectory ?? sys_get_temp_dir();
        $directory = sprintf('%s/jxt-sync-%s', $parent, bin2hex(random_bytes(6)));
        $this->run(static fn (): bool => mkdir($directory . '/files', 0755, true) && chmod($directory, 0755), $directory);

        $files = [];

        foreach ($plan->files as $file) {
            $files[] = ['target' => $file->entry->target, 'source' => $this->stageFile($file, $extensionDirectory, $directory, count($files))];
        }

        $json = json_encode([
            'format' => self::FORMAT,
            'directory_mode' => SyncExecutor::DIRECTORY_MODE,
            'file_mode' => SyncExecutor::FILE_MODE,
            'deletions' => array_map(
                static fn (Deletion $deletion): array => [
                    'target' => $deletion->target,
                    'directory' => $deletion->kind === EntryKind::Directory,
                ],
                $plan->deletions,
            ),
            'directories' => $plan->directories,
            'files' => $files,
            'extension' => [
                'type' => $manifest->type->value,
                'element' => $manifest->element,
                'folder' => $manifest->group ?? '',
                'client_id' => $manifest->client === ClientType::Administrator ? 1 : 0,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $planFile = $directory . '/' . self::PLAN_FILE;
        $this->run(static fn (): bool => file_put_contents($planFile, $json) !== false && chmod($planFile, 0644), $planFile);

        return $directory;
    }

    /**
     * Removes the staging directory including its content; failures are ignored.
     */
    public function remove(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            PhpErrors::capture(static fn (): bool => $item->isDir() ? rmdir($path) : unlink($path));
        }

        PhpErrors::capture(static fn (): bool => rmdir($directory));
    }

    /**
     * Copies a new or changed file into the staging directory and returns its path there, or null if it is
     * unchanged.
     */
    private function stageFile(SyncFile $file, string $extensionDirectory, string $directory, int $index): ?string
    {
        if ($file->status === FileStatus::Unchanged) {
            return null;
        }

        $source = 'files/' . $index;
        $copy = $directory . '/' . $source;
        $original = $extensionDirectory . '/' . $file->entry->source;
        $this->run(static fn (): bool => copy($original, $copy) && chmod($copy, 0644), $copy);

        return $source;
    }

    /**
     * Runs a file operation and turns a failure into an exception.
     *
     * @param Closure(): bool $operation
     *
     * @throws BridgeException if the operation fails
     */
    private function run(Closure $operation, string $path): void
    {
        [$succeeded, $error] = PhpErrors::capture($operation);

        if (!$succeeded) {
            throw new BridgeException(sprintf('Cannot write %s%s', $path, $error === null ? '.' : ': ' . $error));
        }
    }
}

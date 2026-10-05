<?php

declare(strict_types=1);

namespace SmartA\Jxt\Sync;

use SmartA\Jxt\Configuration\Configuration;
use SmartA\Jxt\Deployment\EntryKind;

/**
 * Executes a sync plan on the server.
 *
 * Order: delete, create the extension's directories, copy files. The mode is set for all files
 * and directories of the extension and for newly created directories. Existing shared directories
 * such as `language/de-DE/` remain unchanged.
 */
final readonly class SyncExecutor
{
    public function __construct(private Filesystem $filesystem = new Filesystem())
    {
    }

    /**
     * Executes the plan.
     *
     * @throws \SmartA\Jxt\Deployment\DeploymentException if a file operation fails
     */
    public function execute(SyncPlan $plan, string $extensionDirectory, string $joomlaRoot, Configuration $configuration): void
    {
        foreach ($plan->deletions as $deletion) {
            $path = $joomlaRoot . '/' . $deletion->target;

            match ($deletion->kind) {
                EntryKind::File => $this->filesystem->deleteFile($path),
                EntryKind::Directory => $this->filesystem->deleteDirectory($path),
            };
        }

        foreach ($plan->directories as $directory) {
            $this->ensureDirectory($joomlaRoot, $directory, $configuration);
            $this->filesystem->changeMode($joomlaRoot . '/' . $directory, $configuration->directoryMode);
        }

        foreach ($plan->files as $file) {
            $target = $joomlaRoot . '/' . $file->entry->target;
            $this->ensureDirectory($joomlaRoot, dirname($file->entry->target), $configuration);

            if ($file->status !== FileStatus::Unchanged) {
                $this->filesystem->copyFile($extensionDirectory . '/' . $file->entry->source, $target);
            }

            $this->filesystem->changeMode($target, $configuration->fileMode);
        }
    }

    /**
     * Creates missing directories along the path and sets the mode only for those.
     */
    private function ensureDirectory(string $joomlaRoot, string $directory, Configuration $configuration): void
    {
        $path = $joomlaRoot;

        foreach (explode('/', $directory) as $segment) {
            $path .= '/' . $segment;

            if (!is_dir($path)) {
                $this->filesystem->createDirectory($path);
                $this->filesystem->changeMode($path, $configuration->directoryMode);
            }
        }
    }
}

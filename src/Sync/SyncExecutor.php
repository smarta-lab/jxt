<?php

declare(strict_types=1);

namespace SmartA\Jxt\Sync;

use SmartA\Jxt\Configuration\Configuration;
use SmartA\Jxt\Deployment\EntryKind;

/**
 * Executes a sync plan on the server.
 *
 * Order: delete, create the extension's directories, copy files. The mode is set for all files
 * and directories of the extension and for newly created directories, owner and group only as root.
 * Existing shared directories such as `language/de-DE/` remain unchanged.
 */
final readonly class SyncExecutor
{
    /**
     * @param bool $runsAsRoot Whether the process runs as root and may therefore run `chown`
     */
    public function __construct(
        public bool $runsAsRoot,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * Executes the plan.
     *
     * @throws \SmartA\Jxt\Deployment\DeploymentException if a file operation fails
     */
    public function execute(SyncPlan $plan, string $projectDirectory, string $joomlaRoot, Configuration $configuration): void
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
            $this->applyPermissions($joomlaRoot . '/' . $directory, $configuration->directoryMode, $configuration);
        }

        foreach ($plan->files as $file) {
            $target = $joomlaRoot . '/' . $file->entry->target;
            $this->ensureDirectory($joomlaRoot, dirname($file->entry->target), $configuration);

            if ($file->status !== FileStatus::Unchanged) {
                $this->filesystem->copyFile($projectDirectory . '/' . $file->entry->source, $target);
            }

            $this->applyPermissions($target, $configuration->fileMode, $configuration);
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
                $this->applyPermissions($path, $configuration->directoryMode, $configuration);
            }
        }
    }

    /**
     * Sets the mode and, as root, owner and group.
     */
    private function applyPermissions(string $path, int $mode, Configuration $configuration): void
    {
        $this->filesystem->changeMode($path, $mode);

        if ($this->runsAsRoot) {
            $this->filesystem->changeOwner($path, $configuration->owner, $configuration->group);
        }
    }
}

<?php

declare(strict_types=1);

namespace SmartA\Jxt\Sync;

use Closure;
use SmartA\Jxt\Deployment\DeploymentException;
use SmartA\Jxt\PhpErrors;

/**
 * File operations that throw a DeploymentException with PHP's message on failure.
 */
final class Filesystem
{
    /**
     * Copies a file and overwrites an existing target.
     */
    public function copyFile(string $source, string $target): void
    {
        $this->run(static fn (): bool => copy($source, $target), sprintf('%s kann nicht nach %s kopiert werden', $source, $target));
    }

    /**
     * Deletes a file or a symbolic link.
     */
    public function deleteFile(string $path): void
    {
        $this->run(static fn (): bool => unlink($path), sprintf('%s kann nicht gelöscht werden', $path));
    }

    /**
     * Deletes an empty directory.
     */
    public function deleteDirectory(string $path): void
    {
        $this->run(static fn (): bool => rmdir($path), sprintf('Ordner %s kann nicht gelöscht werden', $path));
    }

    /**
     * Creates a directory whose parent directory already exists.
     */
    public function createDirectory(string $path): void
    {
        $this->run(static fn (): bool => mkdir($path), sprintf('Ordner %s kann nicht angelegt werden', $path));
    }

    /**
     * Sets the mode if it differs.
     */
    public function changeMode(string $path, int $mode): void
    {
        clearstatcache(true, $path);

        if (((int) fileperms($path) & 0777) !== $mode) {
            $this->run(static fn (): bool => chmod($path, $mode), sprintf('Rechte von %s können nicht gesetzt werden', $path));
        }
    }

    /**
     * Sets owner and group if given and different; requires root privileges.
     */
    public function changeOwner(string $path, ?string $owner, ?string $group): void
    {
        clearstatcache(true, $path);

        if ($owner !== null && $this->ownerName($path) !== $owner) {
            $this->run(static fn (): bool => chown($path, $owner), sprintf('Eigentümer von %s kann nicht gesetzt werden', $path));
        }

        if ($group !== null && $this->groupName($path) !== $group) {
            $this->run(static fn (): bool => chgrp($path, $group), sprintf('Gruppe von %s kann nicht gesetzt werden', $path));
        }
    }

    /**
     * Returns the name of the owner.
     */
    private function ownerName(string $path): ?string
    {
        $owner = fileowner($path);
        $entry = $owner === false ? false : posix_getpwuid($owner);

        return $entry === false ? null : $entry['name'];
    }

    /**
     * Returns the name of the group.
     */
    private function groupName(string $path): ?string
    {
        $group = filegroup($path);
        $entry = $group === false ? false : posix_getgrgid($group);

        return $entry === false ? null : $entry['name'];
    }

    /**
     * Runs an operation and turns a failure, including PHP's warning, into an exception.
     *
     * @param Closure(): bool $operation
     */
    private function run(Closure $operation, string $message): void
    {
        [$succeeded, $error] = PhpErrors::capture($operation);

        if (!$succeeded) {
            throw new DeploymentException($message . ($error === null ? '.' : ': ' . $error));
        }
    }
}

<?php

declare(strict_types=1);

namespace SmartA\Jxt\Install;

use SmartA\Jxt\PhpErrors;

/**
 * Installs a ZIP file with the Joomla CLI (`cli/joomla.php extension:install`), the same installer as in the backend.
 *
 * With a web server user, the CLI runs via `sudo -n -u <user>`, so the files belong to the web server and no root
 * privileges are needed. The user cannot read the project directory, therefore the CLI gets a world-readable copy
 * of the ZIP file in the temporary directory, which is deleted afterwards.
 */
final readonly class JoomlaInstaller
{
    public const string CLI = 'cli/joomla.php';

    /**
     * @param string      $phpBinary          PHP binary for the Joomla CLI; must match the sudoers rule exactly
     * @param string|null $temporaryDirectory Directory for the copy of the ZIP file; null for the system default
     */
    public function __construct(
        private ProcessRunner $processRunner = new SystemProcessRunner(),
        private string $phpBinary = PHP_BINARY,
        private ?string $temporaryDirectory = null,
    ) {
    }

    /**
     * Installs or updates the extension from the ZIP file.
     *
     * @param string|null $webUser User that runs the Joomla CLI; null to run it as the current user
     *
     * @throws InstallException if the copy of the ZIP file cannot be created or the CLI cannot be started
     */
    public function install(string $package, string $joomlaRoot, ?string $webUser): ProcessResult
    {
        $copy = $this->readableCopy($package);

        try {
            return $this->processRunner->run($this->command($joomlaRoot, $webUser, $copy));
        } finally {
            PhpErrors::capture(static fn (): bool => unlink($copy));
        }
    }

    /**
     * Returns the sudoers rule that allows the user to run the Joomla installer as web server user without password.
     */
    public function sudoersRule(string $user, string $joomlaRoot, string $webUser): string
    {
        return sprintf(
            '%s ALL=(%s) NOPASSWD: %s %s/%s extension\:install *',
            $user,
            $webUser,
            $this->phpBinary,
            $joomlaRoot,
            self::CLI,
        );
    }

    /**
     * Returns the command line, with `sudo -n` so that a missing sudoers rule fails instead of asking for a password.
     *
     * @return list<string>
     */
    private function command(string $joomlaRoot, ?string $webUser, string $package): array
    {
        $command = [$this->phpBinary, $joomlaRoot . '/' . self::CLI, 'extension:install', '--path=' . $package];

        return $webUser === null ? $command : ['sudo', '-n', '-u', $webUser, ...$command];
    }

    /**
     * Copies the ZIP file with mode 0644 into the temporary directory; the name keeps the extension `.zip`.
     */
    private function readableCopy(string $package): string
    {
        $directory = $this->temporaryDirectory ?? sys_get_temp_dir();
        $copy = sprintf('%s/jxt-%s-%s', $directory, bin2hex(random_bytes(4)), basename($package));
        [$copied, $error] = PhpErrors::capture(static fn (): bool => copy($package, $copy) && chmod($copy, 0644));

        if (!$copied) {
            throw new InstallException(sprintf('Cannot copy %s to %s%s', $package, $directory, $error === null ? '.' : ': ' . $error));
        }

        return $copy;
    }
}

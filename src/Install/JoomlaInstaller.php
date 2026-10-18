<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

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
    private JoomlaCli $cli;

    /**
     * @param string      $phpBinary          PHP binary for the Joomla CLI; must match the sudoers rule exactly
     * @param string|null $temporaryDirectory Directory for the copy of the ZIP file; null for the system default
     */
    public function __construct(
        ProcessRunner $processRunner = new SystemProcessRunner(),
        string $phpBinary = PHP_BINARY,
        private ?string $temporaryDirectory = null,
    ) {
        $this->cli = new JoomlaCli($processRunner, $phpBinary);
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
            return $this->cli->run($joomlaRoot, $webUser, ['extension:install', '--path=' . $copy]);
        } finally {
            PhpErrors::capture(static fn (): bool => unlink($copy));
        }
    }

    /**
     * Returns the sudoers rule that allows the user to run the Joomla installer as web server user without password.
     */
    public function sudoersRule(string $user, string $joomlaRoot, string $webUser): string
    {
        return $this->cli->sudoersRule($user, $joomlaRoot, $webUser, 'extension\:install *');
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

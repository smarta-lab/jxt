<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Install;

use SmartA\Jxt\PhpErrors;
use SmartA\Jxt\Site\TestSite;

/**
 * Installs a ZIP file with the Joomla CLI (`cli/joomla.php extension:install`), the same installer as in the backend.
 *
 * With a web server user, the CLI runs via `sudo -n -u <user>`, so the files belong to the web server and no root
 * privileges are needed. The user cannot read the project directory, therefore the CLI gets a world-readable copy
 * of the ZIP file in the temporary directory, which is deleted afterwards. With the site's `cli_command`, the copy
 * goes to the site's own `tmp/` directory, which the command can see as well.
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
     * @throws \SmartA\Jxt\JxtException if the copy of the ZIP file cannot be created or the CLI cannot be started
     */
    public function install(string $package, TestSite $site): ProcessResult
    {
        $copy = $this->readableCopy($package, $site->stagingDirectory() ?? $this->temporaryDirectory ?? sys_get_temp_dir());

        try {
            return $this->cli->run($site, ['extension:install', '--path=' . $site->cliPath($copy)]);
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
     * Copies the ZIP file with mode 0644 into the directory; the name keeps the extension `.zip`.
     *
     * @throws InstallException if the copy cannot be created
     */
    private function readableCopy(string $package, string $directory): string
    {
        $copy = sprintf('%s/jxt-%s-%s', $directory, bin2hex(random_bytes(4)), basename($package));
        [$copied, $error] = PhpErrors::capture(static fn (): bool => copy($package, $copy) && chmod($copy, 0644));

        if (!$copied) {
            throw new InstallException(sprintf('Cannot copy %s to %s%s', $package, $directory, $error === null ? '.' : ': ' . $error));
        }

        return $copy;
    }
}

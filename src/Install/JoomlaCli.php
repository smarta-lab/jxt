<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Install;

/**
 * Runs commands of the Joomla CLI (`cli/joomla.php`), with a web server user via `sudo -n -u <user>`.
 *
 * `sudo -n` makes a missing sudoers rule fail instead of asking for a password. The PHP binary must match the
 * sudoers rule exactly, since sudo compares the command path.
 */
final readonly class JoomlaCli
{
    public const string SCRIPT = 'cli/joomla.php';

    /**
     * @param string $phpBinary PHP binary for the Joomla CLI
     */
    public function __construct(
        private ProcessRunner $processRunner = new SystemProcessRunner(),
        private string $phpBinary = PHP_BINARY,
    ) {
    }

    /**
     * Runs a command of the Joomla CLI.
     *
     * @param string|null  $webUser   User that runs the Joomla CLI; null to run it as the current user
     * @param list<string> $arguments Command name and its arguments, e.g. `['extension:install', '--path=…']`
     *
     * @throws InstallException if the process cannot be started
     */
    public function run(string $joomlaRoot, ?string $webUser, array $arguments): ProcessResult
    {
        $command = [$this->phpBinary, $joomlaRoot . '/' . self::SCRIPT, ...$arguments];

        return $this->processRunner->run($webUser === null ? $command : ['sudo', '-n', '-u', $webUser, ...$command]);
    }

    /**
     * Returns a sudoers rule that allows the user to run matching commands of the Joomla CLI as web server user
     * without password.
     *
     * @param string $arguments Pattern of the arguments in sudoers syntax, e.g. `extension\:install *`
     */
    public function sudoersRule(string $user, string $joomlaRoot, string $webUser, string $arguments): string
    {
        return sprintf('%s ALL=(%s) NOPASSWD: %s %s/%s %s', $user, $webUser, $this->phpBinary, $joomlaRoot, self::SCRIPT, $arguments);
    }
}

<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Install;

use SmartA\Jxt\Site\SiteException;
use SmartA\Jxt\Site\TestSite;

/**
 * Runs commands of the Joomla CLI (`cli/joomla.php`), with a web server user via `sudo -n -u <user>`, or via the
 * site's `cli_command`, e.g. in a container.
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
     * @param list<string> $arguments Command name and its arguments, e.g. `['extension:install', '--path=…']`;
     *                                paths as the Joomla CLI sees them
     *
     * @throws InstallException if the process cannot be started
     * @throws SiteException    if the Joomla CLI would run as the current user, who cannot write to the site
     */
    public function run(TestSite $site, array $arguments): ProcessResult
    {
        $site->assertWritableWithoutSudo();
        $script = $site->cliRoot . '/' . self::SCRIPT;

        return $this->processRunner->run(match (true) {
            $site->usesCliCommand() => [...$site->cliCommand, $script, ...$arguments],
            $site->webUser === null => [$this->phpBinary, $script, ...$arguments],
            default => ['sudo', '-n', '-u', $site->webUser, $this->phpBinary, $script, ...$arguments],
        });
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

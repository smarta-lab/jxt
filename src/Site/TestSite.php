<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Site;

use SmartA\Jxt\Install\CommandLine;
use SmartA\Jxt\Install\JoomlaCli;

/**
 * The Joomla test site: its directory on this machine and how jxt runs its Joomla CLI.
 *
 * Without `cli_command`, the Joomla CLI runs with the PHP running jxt, via sudo as web server user if one is set.
 * With `cli_command`, e.g. in a container, the command starts PHP and sees the site at `cli_root`; files for the
 * Joomla CLI are then staged in the site's own `tmp/` directory, which the command can see as well.
 */
final readonly class TestSite
{
    /**
     * Path of the site as `$cliCommand` sees it, without trailing `/`.
     */
    public string $cliRoot;

    /**
     * @param string       $joomlaRoot Absolute path of the site on this machine without trailing `/`
     * @param string|null  $webUser    User that runs the Joomla CLI via sudo; null to run it without sudo
     * @param list<string> $cliCommand Command that starts PHP for the Joomla CLI; empty for the PHP running jxt
     * @param string|null  $cliRoot    Path of the site as `$cliCommand` sees it; null if it equals `$joomlaRoot`
     * @param string|null  $id         Id of the site in the configuration; null for the default site
     */
    public function __construct(
        public string $joomlaRoot,
        public ?string $webUser = null,
        public array $cliCommand = [],
        ?string $cliRoot = null,
        public ?string $id = null,
    ) {
        $this->cliRoot = $cliRoot ?? $joomlaRoot;
    }

    /**
     * Checks whether the Joomla CLI runs via `cli_command`.
     */
    public function usesCliCommand(): bool
    {
        return $this->cliCommand !== [];
    }

    /**
     * Checks that the Joomla CLI can write to the site if it runs as the current user, i.e. without `web_user` and
     * `cli_command`.
     *
     * @throws SiteException if the current user cannot write to the site, e.g. because it belongs to the web server
     */
    public function assertWritableWithoutSudo(): void
    {
        if ($this->usesCliCommand() || $this->webUser !== null || is_writable($this->joomlaRoot)) {
            return;
        }

        $owner = is_dir($this->joomlaRoot) ? posix_getpwuid((int) fileowner($this->joomlaRoot)) : false;

        throw new SiteException(sprintf(
            'You cannot write to %s, so the Joomla CLI cannot run as you. Set web_user to the user of the web '
            . 'server so that jxt runs it via sudo, e.g. jxt config set web_user %s%s.',
            $this->joomlaRoot,
            is_array($owner) ? $owner['name'] : 'www-data',
            $this->id === null ? '' : ' --site=' . $this->id,
        ));
    }

    /**
     * Returns the path under which the Joomla CLI sees a path of this machine.
     *
     * @throws SiteException if the path is outside of the site, so that `cli_command` may not see it
     */
    public function cliPath(string $path): string
    {
        if (!$this->usesCliCommand()) {
            return $path;
        }

        if ($path !== $this->joomlaRoot && !str_starts_with($path, $this->joomlaRoot . '/')) {
            throw new SiteException(sprintf(
                '%s is outside of joomla_root %s, so the Joomla CLI of cli_command cannot see it.',
                $path,
                $this->joomlaRoot,
            ));
        }

        return $this->cliRoot . substr($path, strlen($this->joomlaRoot));
    }

    /**
     * Returns the directory for files that the Joomla CLI reads, or null for the system's temporary directory.
     *
     * @throws SiteException if the site's `tmp/` directory is not writable with `cli_command`
     */
    public function stagingDirectory(): ?string
    {
        if (!$this->usesCliCommand()) {
            return null;
        }

        $directory = $this->joomlaRoot . '/tmp';

        if (!is_dir($directory) || !is_writable($directory)) {
            throw new SiteException(sprintf(
                'jxt cannot write to %s, where it puts files for the Joomla CLI. Make it writable for you as well, '
                . 'e.g. with chmod 1777 %s as root in the container.',
                $directory,
                $this->cliPath($directory),
            ));
        }

        return $directory;
    }

    /**
     * Describes how the Joomla CLI runs, for the output.
     */
    public function describeCli(): string
    {
        return match (true) {
            $this->usesCliCommand() => CommandLine::format([...$this->cliCommand, $this->cliRoot . '/' . JoomlaCli::SCRIPT]),
            $this->webUser !== null => 'sudo -u ' . $this->webUser,
            default => 'current user',
        };
    }
}

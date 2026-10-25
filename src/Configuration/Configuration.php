<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Configuration;

use SmartA\Jxt\Site\TestSite;

/**
 * Merged configuration from the global and the project configuration file.
 */
final readonly class Configuration
{
    /**
     * @param string|null       $joomlaRoot    Absolute path of the Joomla test server without trailing `/`
     * @param string|null       $webUser       User of the web server that runs the Joomla CLI for `jxt install`;
     *                                         null to run it as the current user without sudo or via `$cliCommand`
     * @param string            $distDirectory Absolute output directory for `jxt build`
     * @param int               $directoryMode Mode for directories, e.g. 0755
     * @param int               $fileMode      Mode for files, e.g. 0644
     * @param string|null       $manifestFile  Absolute path of the configured manifest; null to locate it
     *                                         in the project directory
     * @param list<string>      $checkExclude  Checks or groups that `jxt check` skips
     * @param list<string>      $checkInclude  Checks or groups that `jxt check` runs despite `$checkExclude`
     * @param list<string>      $cliCommand    Command that starts PHP for the Joomla CLI, e.g. in a container;
     *                                         empty to use the PHP running jxt
     * @param string|null       $cliRoot       Path of the Joomla root as `$cliCommand` sees it, without trailing `/`;
     *                                         null if it equals `$joomlaRoot`
     * @param string|null       $siteConflict  Why the keys of the test site contradict each other; null if they do not
     * @param list<PrepareStep> $prepare       Steps that generate files before building, checking or syncing
     */
    public function __construct(
        public ?string $joomlaRoot,
        public ?string $webUser,
        public string $distDirectory,
        public int $directoryMode,
        public int $fileMode,
        public ?string $manifestFile = null,
        public array $checkExclude = [],
        public array $checkInclude = [],
        public array $cliCommand = [],
        public ?string $cliRoot = null,
        public ?string $siteConflict = null,
        public array $prepare = [],
    ) {
    }

    /**
     * Returns the Joomla root directory after checking that it is configured and contains a Joomla installation.
     *
     * @throws ConfigurationException if `joomla_root` is missing or not a Joomla installation
     */
    public function validJoomlaRoot(): string
    {
        $joomlaRoot = $this->joomlaRoot ?? throw new ConfigurationException(sprintf(
            'joomla_root is not configured. Please set it in ~/.config/jxt/config.json or %s.',
            ConfigurationLoader::PROJECT_FILE,
        ));

        if (!is_file($joomlaRoot . '/configuration.php')) {
            throw new ConfigurationException(sprintf(
                '%s is not a Joomla installation (configuration.php is missing).%s',
                $joomlaRoot,
                $this->cliCommand === []
                    ? ''
                    : ' With cli_command, joomla_root is the directory of the site on this machine, shared with the '
                        . 'container as bind mount, and cli_root its path in the container; a named volume is not supported.',
            ));
        }

        return $joomlaRoot;
    }

    /**
     * Returns the test site after checking that its keys are consistent and `joomla_root` contains a Joomla
     * installation.
     *
     * @throws ConfigurationException if the keys of the test site contradict each other, or `joomla_root` is missing
     *                                or not a Joomla installation
     */
    public function testSite(): TestSite
    {
        if ($this->siteConflict !== null) {
            throw new ConfigurationException($this->siteConflict);
        }

        return new TestSite($this->validJoomlaRoot(), $this->webUser, $this->cliCommand, $this->cliRoot);
    }
}

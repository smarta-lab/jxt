<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Configuration;

use SmartA\Jxt\Site\TestSite;

/**
 * Merged configuration from the global and the project configuration file, with the selected test site.
 */
final readonly class Configuration
{
    /**
     * @param string            $distDirectory Absolute output directory for `jxt build`
     * @param string|null       $manifestFile  Absolute path of the configured manifest; null to locate it
     *                                         in the project directory
     * @param list<string>      $checkExclude  Checks or groups that `jxt check` skips
     * @param list<string>      $checkInclude  Checks or groups that `jxt check` runs despite `$checkExclude`
     * @param list<PrepareStep> $prepare       Steps that generate files before building, checking or syncing
     * @param SiteEntry|null    $site          The selected test site; null if it is not configured
     * @param SiteSelection     $siteSelection How the test site was selected
     * @param string            $missingSite   Why the selected test site cannot be used if it is not configured
     */
    public function __construct(
        public string $distDirectory,
        public ?string $manifestFile = null,
        public array $checkExclude = [],
        public array $checkInclude = [],
        public array $prepare = [],
        public ?SiteEntry $site = null,
        public SiteSelection $siteSelection = new SiteSelection(),
        public string $missingSite = 'No test site is configured.',
    ) {
    }

    /**
     * Returns why the selected test site cannot be used: it is not configured, its keys contradict each other or
     * `joomla_root` is missing; null if it can be used as far as the configuration tells.
     */
    public function siteProblem(): ?string
    {
        return match (true) {
            $this->site === null => $this->missingSite,
            $this->site->conflict() !== null => $this->site->conflict(),
            $this->site->joomlaRoot === null => self::missingJoomlaRoot($this->site),
            default => null,
        };
    }

    /**
     * Returns the Joomla root directory after checking that the site is configured and contains a Joomla
     * installation.
     *
     * @throws ConfigurationException if the site or its `joomla_root` is missing or not a Joomla installation
     */
    public function validJoomlaRoot(): string
    {
        $site = $this->site ?? throw new ConfigurationException($this->missingSite);
        $joomlaRoot = $site->joomlaRoot ?? throw new ConfigurationException(self::missingJoomlaRoot($site));

        if (!is_file($joomlaRoot . '/configuration.php')) {
            throw new ConfigurationException(sprintf(
                '%s is not a Joomla installation (configuration.php is missing).%s',
                $joomlaRoot,
                $site->cliCommand === []
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
     * @throws ConfigurationException if the site is missing, its keys contradict each other, or `joomla_root` is
     *                                missing or not a Joomla installation
     */
    public function testSite(): TestSite
    {
        $conflict = $this->site?->conflict();

        if ($conflict !== null) {
            throw new ConfigurationException($conflict);
        }

        $joomlaRoot = $this->validJoomlaRoot();
        $site = $this->site ?? throw new ConfigurationException($this->missingSite);

        return new TestSite($joomlaRoot, $site->webUser, $site->cliCommand, $site->cliRoot, $site->id);
    }

    /**
     * Returns the message for a site without `joomla_root`.
     */
    private static function missingJoomlaRoot(SiteEntry $site): string
    {
        return sprintf(
            'joomla_root is not set in %s of %s. Please set it with jxt config set joomla_root <path> --site=%s.',
            $site->name(),
            $site->source,
            $site->id,
        );
    }
}

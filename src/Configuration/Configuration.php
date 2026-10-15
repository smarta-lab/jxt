<?php

declare(strict_types=1);

namespace SmartA\Jxt\Configuration;

/**
 * Merged configuration from the global and the project configuration file.
 */
final readonly class Configuration
{
    /**
     * @param string|null  $joomlaRoot    Absolute path of the Joomla test server without trailing `/`
     * @param string|null  $webUser       User of the web server that runs the Joomla CLI for `jxt install`;
     *                                    null to run it as the current user without sudo
     * @param string       $distDirectory Absolute output directory for `jxt build`
     * @param int          $directoryMode Mode for directories, e.g. 0755
     * @param int          $fileMode      Mode for files, e.g. 0644
     * @param string|null  $manifestFile  Absolute path of the configured manifest; null to locate it
     *                                    in the project directory
     * @param list<string> $checkExclude  Checks or groups that `jxt check` skips
     * @param list<string> $checkInclude  Checks or groups that `jxt check` runs despite `$checkExclude`
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
            throw new ConfigurationException(
                sprintf('%s is not a Joomla installation (configuration.php is missing).', $joomlaRoot),
            );
        }

        return $joomlaRoot;
    }
}

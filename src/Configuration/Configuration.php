<?php

declare(strict_types=1);

namespace SmartA\Jxt\Configuration;

/**
 * Merged configuration from the global and the project configuration file.
 */
final readonly class Configuration
{
    /**
     * @param string|null $joomlaRoot     Absolute path of the Joomla test server without trailing `/`
     * @param string|null $owner          Owner of the deployed files (only effective as root)
     * @param string|null $group          Group of the deployed files (only effective as root)
     * @param string      $distDirectory  Absolute output directory for `jxt build`
     * @param int         $directoryMode  Mode for directories, e.g. 0755
     * @param int         $fileMode       Mode for files, e.g. 0644
     */
    public function __construct(
        public ?string $joomlaRoot,
        public ?string $owner,
        public ?string $group,
        public string $distDirectory,
        public int $directoryMode,
        public int $fileMode,
    ) {
    }
}

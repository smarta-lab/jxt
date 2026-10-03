<?php

declare(strict_types=1);

namespace SmartA\Jxt\Manifest;

/**
 * Manifest data that only the Joomla installer evaluates and that `jxt sync` therefore cannot reproduce.
 */
final readonly class InstallerMetadata
{
    /**
     * @param string|null           $namespace     Content of `<namespace>`
     * @param string|null           $namespacePath Attribute `path` of `<namespace>`
     * @param array<string, string> $sections      Normalized XML of `<scriptfile>`, `<install>`, `<uninstall>`,
     *                                             `<update>` and `<updateservers>`, per section
     * @param list<string>          $targets       Target paths of the install script, SQL files and schema directories
     *                                             (relative to the Joomla root directory)
     */
    public function __construct(
        public ?string $namespace,
        public ?string $namespacePath,
        public array $sections,
        public array $targets,
    ) {
    }
}

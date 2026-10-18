<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Manifest;

use SmartA\Jxt\Deployment\DeploymentPlan;

/**
 * Result of the manifest parser: metadata of the extension and the deployment plan derived from it.
 */
final readonly class Manifest
{
    /**
     * @param string      $element  Technical name of the extension, e.g. `mod_example`, `cassiopeia` or (for
     *                              the plugin `plg_system_example`) `example`
     * @param string|null $version  Content of `<version>`, if present
     * @param string      $fileName File name of the manifest
     * @param string|null $group    Plugin group (attribute `group`), null for other types
     */
    public function __construct(
        public ExtensionType $type,
        public ClientType $client,
        public string $element,
        public ?string $version,
        public string $fileName,
        public DeploymentPlan $plan,
        public InstallerMetadata $installer,
        public ?string $group = null,
    ) {
    }

    /**
     * Returns the name of the extension as used by Joomla for language files, language constants and packages,
     * e.g. `mod_example`, `tpl_cassiopeia` or `plg_system_example`.
     */
    public function name(): string
    {
        return match ($this->type) {
            ExtensionType::Module => $this->element,
            ExtensionType::Template => 'tpl_' . $this->element,
            ExtensionType::Plugin => strtolower('plg_' . $this->group . '_' . $this->element),
        };
    }

    /**
     * Returns the path of the installed manifest relative to the Joomla root directory.
     */
    public function installedManifest(): string
    {
        return $this->type->installDirectory($this->client, $this->element, $this->group) . '/' . $this->fileName;
    }
}

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
     * @param string      $element  Technical name of the extension, e.g. `mod_example`, `cassiopeia`,
     *                              `com_example`, (for the plugin `plg_system_example`) `example` or
     *                              (for a library) `acme/example`
     * @param string|null $version  Content of `<version>`, if present
     * @param string      $fileName File name of the manifest
     * @param string|null $group       Plugin group (attribute `group`), null for other types
     * @param string      $title       Content of `<name>`, which Joomla translates
     * @param bool        $inheritable Template with `<inheritable>1</inheritable>`, whose files Joomla looks for in
     *                                 `media/templates/<client>/<element>/`
     * @param string|null $parent      Parent of a child template (`<parent>`), null otherwise
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
        public string $title = '',
        public bool $inheritable = false,
        public ?string $parent = null,
    ) {
    }

    /**
     * Returns the name of the extension as used by Joomla for language files, language constants and packages,
     * e.g. `mod_example`, `tpl_cassiopeia`, `plg_system_example`, `com_example` or `lib_acme_example`.
     */
    public function name(): string
    {
        return match ($this->type) {
            ExtensionType::Module, ExtensionType::Component => $this->element,
            ExtensionType::Template => 'tpl_' . $this->element,
            ExtensionType::Plugin => strtolower('plg_' . $this->group . '_' . $this->element),
            ExtensionType::Library => 'lib_' . str_replace('/', '_', $this->element),
        };
    }

    /**
     * Returns the path of the installed manifest relative to the Joomla root directory.
     */
    public function installedManifest(): string
    {
        return $this->type->manifestTarget($this->client, $this->element, $this->fileName, $this->group);
    }
}

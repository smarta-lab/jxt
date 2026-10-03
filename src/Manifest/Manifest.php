<?php

declare(strict_types=1);

namespace SmartA\Jxt\Manifest;

use SmartA\Jxt\Deployment\DeploymentPlan;

/**
 * Result of the manifest parser: metadata of the extension and the deployment plan derived from it.
 */
final readonly class Manifest
{
    /**
     * @param string      $element  Technical name of the extension, e.g. `mod_example` or `cassiopeia`
     * @param string|null $version  Content of `<version>`, if present
     * @param string      $fileName File name of the manifest
     */
    public function __construct(
        public ExtensionType $type,
        public ClientType $client,
        public string $element,
        public ?string $version,
        public string $fileName,
        public DeploymentPlan $plan,
        public InstallerMetadata $installer,
    ) {
    }

    /**
     * Returns the path of the installed manifest relative to the Joomla root directory.
     */
    public function installedManifest(): string
    {
        return $this->type->installDirectory($this->client, $this->element) . '/' . $this->fileName;
    }
}

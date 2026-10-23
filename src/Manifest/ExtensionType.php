<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Manifest;

use LogicException;

/**
 * Supported extension types (attribute `type` in the manifest).
 */
enum ExtensionType: string
{
    case Module = 'module';
    case Template = 'template';
    case Plugin = 'plugin';
    case Component = 'component';
    case Library = 'library';

    /**
     * Returns the install directory of the extension relative to the Joomla root directory.
     *
     * It receives the install script and, except for libraries, the manifest. Plugins are always installed in
     * `plugins/<group>/<element>`, regardless of the client; for components it is the administrator directory
     * `administrator/components/<element>`, the base of the SQL files as well; libraries go to
     * `libraries/<element>`, where the element may contain `/`.
     *
     * @param string|null $group Plugin group (attribute `group` in the manifest), null for other types
     */
    public function installDirectory(ClientType $client, string $element, ?string $group = null): string
    {
        return match ($this) {
            self::Module => $client->pathPrefix() . 'modules/' . $element,
            self::Template => $client->pathPrefix() . 'templates/' . $element,
            self::Plugin => 'plugins/' . ($group ?? throw new LogicException('A plugin needs a group.')) . '/' . $element,
            self::Component => 'administrator/components/' . $element,
            self::Library => 'libraries/' . $element,
        };
    }

    /**
     * Returns the path of the installed manifest relative to the Joomla root directory.
     *
     * The Joomla installer copies the manifest into the install directory, except for libraries: their manifest
     * goes to `administrator/manifests/libraries/<element>.xml`, renamed after the element.
     *
     * @param string      $fileName File name of the manifest in the extension directory
     * @param string|null $group    Plugin group (attribute `group` in the manifest), null for other types
     */
    public function manifestTarget(ClientType $client, string $element, string $fileName, ?string $group = null): string
    {
        return $this === self::Library
            ? 'administrator/manifests/libraries/' . $element . '.xml'
            : $this->installDirectory($client, $element, $group) . '/' . $fileName;
    }

    /**
     * Returns all directories that the Joomla installer creates for the extension, the install directory first.
     *
     * For components these are the directories of the administrator, the site and the API, which the installer
     * creates even if the manifest lists no files for them.
     *
     * @param string|null $group Plugin group (attribute `group` in the manifest), null for other types
     *
     * @return list<string>
     */
    public function installDirectories(ClientType $client, string $element, ?string $group = null): array
    {
        return match ($this) {
            self::Component => [
                $this->installDirectory($client, $element),
                'components/' . $element,
                'api/components/' . $element,
            ],
            default => [$this->installDirectory($client, $element, $group)],
        };
    }

    /**
     * Returns the client whose language directory receives the files listed in `<languages>`.
     *
     * The Joomla installer copies the language files of plugins to `administrator/language/`. For components
     * this is the section `<languages>` directly below `<extension>`, whose files go to the site; those in
     * `<administration>` go to the administrator.
     */
    public function languageClient(ClientType $client): ClientType
    {
        return match ($this) {
            self::Plugin => ClientType::Administrator,
            self::Component => ClientType::Site,
            default => $client,
        };
    }
}

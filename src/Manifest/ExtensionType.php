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

    /**
     * Returns the install directory of the extension relative to the Joomla root directory.
     *
     * Plugins are always installed in `plugins/<group>/<element>`, regardless of the client.
     *
     * @param string|null $group Plugin group (attribute `group` in the manifest), null for other types
     */
    public function installDirectory(ClientType $client, string $element, ?string $group = null): string
    {
        return match ($this) {
            self::Module => $client->pathPrefix() . 'modules/' . $element,
            self::Template => $client->pathPrefix() . 'templates/' . $element,
            self::Plugin => 'plugins/' . ($group ?? throw new LogicException('A plugin needs a group.')) . '/' . $element,
        };
    }

    /**
     * Returns the client whose language directory receives the files listed in `<languages>`.
     *
     * The Joomla installer copies the language files of plugins to `administrator/language/`.
     */
    public function languageClient(ClientType $client): ClientType
    {
        return $this === self::Plugin ? ClientType::Administrator : $client;
    }
}

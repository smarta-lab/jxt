<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Manifest;

/**
 * Supported extension types (attribute `type` in the manifest).
 */
enum ExtensionType: string
{
    case Module = 'module';
    case Template = 'template';

    /**
     * Returns the install directory of the extension relative to the Joomla root directory.
     */
    public function installDirectory(ClientType $client, string $element): string
    {
        $baseDirectory = match ($this) {
            self::Module => 'modules',
            self::Template => 'templates',
        };

        return $client->pathPrefix() . $baseDirectory . '/' . $element;
    }
}

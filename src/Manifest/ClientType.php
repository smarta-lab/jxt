<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Manifest;

/**
 * Application area of an extension (attribute `client` in the manifest).
 */
enum ClientType: string
{
    case Site = 'site';
    case Administrator = 'administrator';

    /**
     * Returns the prefix under which the area is located relative to the Joomla root directory.
     */
    public function pathPrefix(): string
    {
        return match ($this) {
            self::Site => '',
            self::Administrator => 'administrator/',
        };
    }
}

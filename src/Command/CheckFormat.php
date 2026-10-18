<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Command;

/**
 * Output format of `jxt check`.
 */
enum CheckFormat: string
{
    /**
     * Findings grouped by section, for people.
     */
    case Text = 'text';

    /**
     * Findings as GitHub Actions workflow commands, shown as annotations.
     */
    case Github = 'github';

    /**
     * Returns the values of all formats.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $format): string => $format->value, self::cases());
    }
}

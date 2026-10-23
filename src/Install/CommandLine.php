<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Install;

/**
 * Formats a command, which jxt runs without a shell, as command line for the output.
 */
final class CommandLine
{
    /**
     * Returns the arguments separated by spaces; arguments that a shell would split or interpret are quoted.
     *
     * @param list<string> $arguments
     */
    public static function format(array $arguments): string
    {
        return implode(' ', array_map(
            static fn (string $argument): string => preg_match('#^[\w@%+=:,./-]+$#', $argument) === 1
                ? $argument
                : escapeshellarg($argument),
            $arguments,
        ));
    }
}

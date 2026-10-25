<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Command;

use SmartA\Jxt\Configuration\ConfigurationException;
use Symfony\Component\Console\Command\Command;

/**
 * Rejects arguments that an action does not use, so that e.g. `site=local` instead of `--site=local` is not ignored.
 */
final class UnusedArguments
{
    /**
     * Throws if one of the arguments is given, with a hint if it looks like an option of the command without `--`.
     *
     * @param list<mixed> $arguments Values of the arguments the action does not use; null or empty if not given
     * @param string      $action    The action for the message, e.g. `jxt config show`
     *
     * @throws ConfigurationException if an argument is given
     */
    public static function reject(Command $command, array $arguments, string $action): void
    {
        $given = array_values(array_filter(
            array_merge(...array_map(static fn (mixed $argument): array => (array) $argument, $arguments)),
            static fn (mixed $argument): bool => is_string($argument) && $argument !== '',
        ));

        if ($given === []) {
            return;
        }

        $hints = array_filter(array_map(
            static fn (string $argument): ?string => preg_match('/^([a-z][a-z-]*)(=.*)?$/', $argument, $match) === 1
                && $command->getDefinition()->hasOption($match[1])
                    ? sprintf(' Did you mean --%s?', $argument)
                    : null,
            $given,
        ));

        throw new ConfigurationException(sprintf(
            '%s takes no further arguments, but got %s.%s',
            $action,
            implode(' ', array_map(static fn (string $argument): string => '"' . $argument . '"', $given)),
            implode('', $hints),
        ));
    }
}

<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Plugin\Console\Jxt\Plan;

/**
 * Checks that a target of the plan lies in a directory that belongs to a single extension.
 *
 * The plugin trusts jxt, since whoever may run the Joomla CLI as the web server user can install any code anyway.
 * The check guards against mistakes, e.g. a deletion of `configuration.php` or of all modules.
 */
final class TargetPath
{
    /**
     * Allowed prefixes with the minimum and maximum number of path segments.
     *
     * @var list<array{list<string>, int, int}>
     */
    private const array RULES = [
        [['modules'], 2, PHP_INT_MAX],
        [['administrator', 'modules'], 3, PHP_INT_MAX],
        [['templates'], 2, PHP_INT_MAX],
        [['administrator', 'templates'], 3, PHP_INT_MAX],
        [['plugins'], 3, PHP_INT_MAX],
        [['components'], 2, PHP_INT_MAX],
        [['administrator', 'components'], 3, PHP_INT_MAX],
        [['api', 'components'], 3, PHP_INT_MAX],
        [['media'], 2, PHP_INT_MAX],
        [['language'], 3, 3],
        [['administrator', 'language'], 4, 4],
        [['administrator', 'cache', 'autoload_psr4.php'], 3, 3],
    ];

    /**
     * Returns the target if it is a normalized relative path inside the directories of extensions.
     *
     * @throws PlanException otherwise
     */
    public static function check(string $target): string
    {
        $segments = explode('/', $target);

        foreach ($segments as $segment) {
            if (in_array($segment, ['', '.', '..'], true) || preg_match('/[\\\\\x00]/', $segment) === 1) {
                throw new PlanException(sprintf('Invalid target "%s" in the plan.', $target));
            }
        }

        foreach (self::RULES as [$prefix, $minimum, $maximum]) {
            $count = count($segments);

            if (array_slice($segments, 0, count($prefix)) === $prefix && $count >= $minimum && $count <= $maximum) {
                return $target;
            }
        }

        throw new PlanException(sprintf('Target "%s" in the plan is outside the directories of extensions.', $target));
    }
}

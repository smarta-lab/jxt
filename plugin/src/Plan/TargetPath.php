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
     * Names of Joomla's own directories in `libraries/` and of its own libraries, which a library must not use as
     * its first path segment; jxt reserves the same.
     */
    public const array RESERVED_LIBRARY_NAMES = ['joomla', 'php-encryption', 'phpass', 'src', 'vendor'];

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

        if (self::isLibraryTarget($segments)) {
            return $target;
        }

        throw new PlanException(sprintf('Target "%s" in the plan is outside the directories of extensions.', $target));
    }

    /**
     * Checks whether the target is inside `libraries/<name>/` or the manifest
     * `administrator/manifests/libraries/<name>.xml` of a library whose name does not start with a reserved one.
     *
     * @param list<string> $segments
     */
    private static function isLibraryTarget(array $segments): bool
    {
        if ($segments[0] === 'libraries' && count($segments) >= 2) {
            return !in_array($segments[1], self::RESERVED_LIBRARY_NAMES, true);
        }

        if (array_slice($segments, 0, 3) !== ['administrator', 'manifests', 'libraries'] || count($segments) < 4) {
            return false;
        }

        $name = count($segments) === 4 ? substr($segments[3], 0, -strlen('.xml')) : $segments[3];

        return str_ends_with((string) end($segments), '.xml')
            && $name !== ''
            && !in_array($name, self::RESERVED_LIBRARY_NAMES, true);
    }
}
